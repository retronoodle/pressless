<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\Tests\Support\TestRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage for the admin-only "Seed default collections" action.
 *
 * Exercises: creating missing collections on an empty DB, the idempotent
 * "already present" path when both collections exist, and rejection of
 * non-admin requests.
 */
final class SeedDefaultCollectionsSmokeTest extends TestCase
{
    private const ADMIN_PASSWORD = 'admin-password-1';
    private const EDITOR_PASSWORD = 'editor-password-1';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Connection $connection;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $auth;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-seed-default-collections-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $file) {
            copy($file, $this->projectRoot . '/database/migrations/' . basename($file));
        }
        $this->dbPath = $this->projectRoot . '/var/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'production',
            [
                'app' => ['debug' => false, 'url' => 'http://localhost'],
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
                'sessions' => ['name' => 'stead_seed_defaults'],
            ],
        );

        $this->installTemplates();
        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $this->users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $this->auth = new AuthenticationService($this->users, $sessions, $hasher, $this->store, 3600);

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, TestRenderer::twig($this->config, $this->connection));
        $this->kernel = new Kernel($app, $router);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/templates",
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/var/cache",
            "{$this->projectRoot}/var/log",
            "{$this->projectRoot}/var",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
    }

    public function testAdminSeedingCreatesBothCollectionsAndReportsCountViaFlash(): void
    {
        $admin = $this->users->create(
            'admin@example.com',
            'Admin',
            self::ADMIN_PASSWORD,
            User::ROLE_ADMIN,
            true,
        );
        $this->login($admin);

        $this->assertSame(0, $this->collectionCount());

        $response = $this->post('/admin/settings/seed-default-collections', []);
        $this->assertSame(303, $response->getStatusCode());

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('/admin/settings?', $location);

        $query = [];
        parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $query);
        $this->assertSame('Created 2 default collections.', $query['flash'] ?? '');

        $this->assertSame(2, $this->collectionCount());
        $this->assertNotNull($this->connection->fetchOne("SELECT id FROM collections WHERE slug = 'pages'"));
        $this->assertNotNull($this->connection->fetchOne("SELECT id FROM collections WHERE slug = 'posts'"));

        // Follow-up visit reads the flash from the query string.
        $page = $this->get('/admin/settings?' . http_build_query($query));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('Created 2 default collections.', (string) $page->getContent());
    }

    public function testAdminSeedingWhenCollectionsExistReportsAlreadyPresent(): void
    {
        $admin = $this->users->create(
            'admin@example.com',
            'Admin',
            self::ADMIN_PASSWORD,
            User::ROLE_ADMIN,
            true,
        );
        $this->login($admin);

        $first = $this->post('/admin/settings/seed-default-collections', []);
        $this->assertSame(303, $first->getStatusCode());

        $second = $this->post('/admin/settings/seed-default-collections', []);
        $this->assertSame(303, $second->getStatusCode());

        $query = [];
        parse_str(parse_url((string) $second->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
        $this->assertSame('Default collections are already present.', $query['flash'] ?? '');

        $this->assertSame(2, $this->collectionCount(), 'rerun must not duplicate rows.');
    }

    public function testAdminSeedingReportsPartialCreationWhenOnlyOneCollectionExists(): void
    {
        $admin = $this->users->create(
            'admin@example.com',
            'Admin',
            self::ADMIN_PASSWORD,
            User::ROLE_ADMIN,
            true,
        );
        $this->login($admin);

        // Pre-create the pages collection so only posts is missing.
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at) '
            . "VALUES ('pages', 'Pages', '{}', '2025-01-01 00:00:00', '2025-01-01 00:00:00')",
        );

        $response = $this->post('/admin/settings/seed-default-collections', []);
        $this->assertSame(303, $response->getStatusCode());

        $query = [];
        parse_str(parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
        $this->assertSame('Created 1 default collection.', $query['flash'] ?? '');
        $this->assertSame(2, $this->collectionCount());
    }

    public function testNonAdminIsRejectedFromSeedEndpoint(): void
    {
        $editor = $this->users->create(
            'editor@example.com',
            'Editor',
            self::EDITOR_PASSWORD,
            User::ROLE_EDITOR,
            true,
        );
        $this->login($editor);

        $response = $this->post('/admin/settings/seed-default-collections', []);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->collectionCount());
    }

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create($path));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function post(string $path, array $parameters): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create($path, 'POST', $parameters));
    }

    private function login(User $user): void
    {
        $this->store->start();
        $password = $user->roleName === User::ROLE_ADMIN ? self::ADMIN_PASSWORD : self::EDITOR_PASSWORD;
        $this->auth->attempt($user->email, $password);
    }

    private function collectionCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections')['c'] ?? 0);
    }

    private function installTemplates(): void
    {
        $templatesDir = $this->projectRoot . '/templates';
        foreach (['layout', 'admin', 'admin/settings'] as $sub) {
            mkdir($templatesDir . '/' . $sub, 0775, true);
        }
        file_put_contents(
            $templatesDir . '/layout/base.twig',
            "<!doctype html><html><head></head><body>{% block body %}{% endblock %}</body></html>\n",
        );
        file_put_contents(
            $templatesDir . '/admin/settings/index.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% block body %}{{ flash|default('') }} {{ seed_url|default('') }}"
            . " {{ values.site_name|default('') }}{% endblock %}\n",
        );
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}