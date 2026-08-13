<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use PHPUnit\Framework\TestCase;
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
use Stead\Settings\Settings;
use Stead\Tests\Support\TestRenderer;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage for the homepage section of the /admin/settings screen.
 *
 * Exercises: the effective-type banner, saving a static-page override,
 * clearing an override back to the theme default, non-admin rejection,
 * and the invalid-entry-id rejection path.
 */
final class SettingsHomepageAdminTest extends TestCase
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
        $this->projectRoot = sys_get_temp_dir() . '/stead-settings-homepage-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $file) {
            copy($file, $this->projectRoot . '/database/migrations/' . basename($file));
        }
        $this->copyDir(__DIR__ . '/../../templates', $this->projectRoot . '/templates');
        $this->copyDir(__DIR__ . '/../../themes/starter', $this->projectRoot . '/themes/starter');
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
                    'theme' => 'themes',
                ],
                'theme' => ['active' => 'starter'],
                'sessions' => ['name' => 'stead_settings_homepage'],
            ],
        );

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

        $this->users->create('admin@example.com', 'Admin', self::ADMIN_PASSWORD, User::ROLE_ADMIN, true);
        $this->users->create('editor@example.com', 'Editor', self::EDITOR_PASSWORD, User::ROLE_EDITOR, true);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/themes",
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

    public function testIndexShowsThemeDefaultBannerWhenNoOverride(): void
    {
        $this->login('admin@example.com');

        $page = $this->get('/admin/settings');
        $this->assertSame(200, $page->getStatusCode());
        $body = (string) $page->getContent();
        $this->assertStringContainsString('Currently serving', $body);
        $this->assertStringContainsString('built-in fallback', $body, 'no theme.json means no declared default');
    }

    public function testIndexShowsThemeDefaultWhenManifestDeclaresStaticPage(): void
    {
        file_put_contents(
            $this->projectRoot . '/themes/starter/theme.json',
            json_encode(['name' => 'Starter', 'homepage_type' => 'static_page']),
        );
        $this->login('admin@example.com');

        $page = $this->get('/admin/settings');
        $body = (string) $page->getContent();
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('theme default', $body);
        $this->assertStringContainsString('static page', $body);
    }

    public function testSavingStaticPageOverrideStoresAndRenders(): void
    {
        $pagesId = $this->seedCollection('pages', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $entryId = $this->seedEntry($pagesId, 'welcome', 'Welcome');
        $this->login('admin@example.com');

        $response = $this->post('/admin/settings', [
            'site_name' => 'Site',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'homepage_action' => 'static_page',
            'homepage_page_id' => (string) $entryId,
        ]);

        $this->assertSame(303, $response->getStatusCode());

        $settings = $this->loadSettings();
        $this->assertSame(Settings::HOMEPAGE_TYPE_STATIC_PAGE, $settings->homepageType);
        $this->assertSame($entryId, $settings->homepagePageId);

        $page = $this->get('/admin/settings');
        $body = (string) $page->getContent();
        $this->assertStringContainsString('admin override', $body);
        $this->assertStringContainsString(
            sprintf('value="%d"', $entryId),
            $body,
            'the picked entry should be in the picker.',
        );
        $this->assertMatchesRegularExpression(
            sprintf('/value="%d"\s+selected/', $entryId),
            $body,
            'the selected entry should be marked selected in the picker.',
        );
    }

    public function testClearingOverrideResetsBothColumns(): void
    {
        $pagesId = $this->seedCollection('pages', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $entryId = $this->seedEntry($pagesId, 'welcome', 'Welcome');
        $this->login('admin@example.com');

        $this->post('/admin/settings', [
            'site_name' => 'Site',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'homepage_action' => 'static_page',
            'homepage_page_id' => (string) $entryId,
        ]);
        $this->assertSame(Settings::HOMEPAGE_TYPE_STATIC_PAGE, $this->loadSettings()->homepageType);

        $this->post('/admin/settings', [
            'site_name' => 'Site',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'homepage_action' => 'use_theme_default',
        ]);

        $settings = $this->loadSettings();
        $this->assertNull($settings->homepageType);
        $this->assertNull($settings->homepagePageId);
    }

    public function testInvalidEntryIdIsRejected(): void
    {
        $this->login('admin@example.com');

        $response = $this->post('/admin/settings', [
            'site_name' => 'Site',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'homepage_action' => 'static_page',
            'homepage_page_id' => '999999',
        ]);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'invalid entry id must surface as a form error, not a 500.',
        );
        $body = (string) $response->getContent();
        $this->assertStringContainsString('homepage_page_id', $body);

        $settings = $this->loadSettings();
        $this->assertNull($settings->homepageType, 'invalid input must not be persisted.');
        $this->assertNull($settings->homepagePageId);
    }

    public function testNonAdminCannotSaveHomepageChange(): void
    {
        $pagesId = $this->seedCollection('pages', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $entryId = $this->seedEntry($pagesId, 'welcome', 'Welcome');
        $this->login('editor@example.com');

        $response = $this->post('/admin/settings', [
            'site_name' => 'Site',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'homepage_action' => 'static_page',
            'homepage_page_id' => (string) $entryId,
        ]);

        $this->assertSame(403, $response->getStatusCode());

        $settings = $this->loadSettings();
        $this->assertNull($settings->homepageType);
        $this->assertNull($settings->homepagePageId);
    }

    private function loadSettings(): Settings
    {
        $row = $this->connection->fetchOne(
            'SELECT site_name, timezone, date_format, homepage_type, homepage_page_id FROM settings WHERE id = 1',
        );
        if ($row === null) {
            return Settings::defaults();
        }
        return Settings::fromRow($row);
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function seedCollection(string $slug, array $fields): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            [
                'slug' => $slug,
                'name' => ucfirst($slug),
                'schema' => json_encode(['fields' => $fields]),
                'ts' => $now,
            ],
        );
        return (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => $slug],
        )['id'];
    }

    private function seedEntry(int $collectionId, string $slug, string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:cid, :slug, :title, :status, :ts, :ts)',
            ['cid' => $collectionId, 'slug' => $slug, 'title' => $title, 'status' => 'published', 'ts' => $now],
        );
        $entryId = (int) $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :cid AND slug = :slug',
            ['cid' => $collectionId, 'slug' => $slug],
        )['id'];
        $this->connection->execute(
            'INSERT INTO entry_values (entry_id, field_key, field_type, value, value_text, created_at, updated_at)
             VALUES (:eid, :key, :type, :value, :value_text, :ts, :ts)',
            [
                'eid' => $entryId,
                'key' => 'title',
                'type' => 'text',
                'value' => $title,
                'value_text' => $title,
                'ts' => $now,
            ],
        );
        return $entryId;
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

    private function login(string $email): void
    {
        $this->store->start();
        $password = $email === 'admin@example.com' ? self::ADMIN_PASSWORD : self::EDITOR_PASSWORD;
        $this->auth->attempt($email, $password);
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $src . '/' . $entry;
            $to = $dst . '/' . $entry;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
