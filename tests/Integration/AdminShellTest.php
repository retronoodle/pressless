<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\UserRepository;
use Stead\Auth\User;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\Tests\Support\TestRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminShellTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $authService;
    private UserRepository $users;
    private string $templatesDir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-shell-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->dbPath = $this->projectRoot . '/var/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'production',
            [
                'app' => ['debug' => false],
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
                'sessions' => ['name' => 'stead_session'],
            ],
        );

        $this->templatesDir = $this->projectRoot . '/templates';
        mkdir($this->templatesDir, 0775, true);
        $this->installTemplates();

        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $this->users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $this->authService = new AuthenticationService(
            $this->users,
            $sessions,
            $hasher,
            $this->store,
            3600,
        );

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

    public function testLoginPageRendersTheLoginForm(): void
    {
        $response = $this->kernel->handle(Request::create('/admin/login'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Sign in to Stead', $body);
        $this->assertStringContainsString('action="/admin/login"', $body);
        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('autocomplete="current-password"', $body);
    }

    public function testLoginPageEscapesUserSuppliedEmail(): void
    {
        $response = $this->kernel->handle(Request::create(
            '/admin/login',
            'POST',
            ['email' => '<script>x</script>@x', 'password' => 'nope'],
        ));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('<script>x</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testAdminShellIsProtectedBeforeLogin(): void
    {
        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login?redirect=%2Fadmin', $response->headers->get('Location'));
    }

    public function testAuthenticatedAdminShellRendersTheEmptyState(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Stead admin', $body);
        $this->assertStringContainsString('Create your first collection', $body);
        $this->assertStringContainsString('href="/admin/collections/new"', $body);
        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringContainsString('action="/admin/logout"', $body);
    }

    public function testDashboardRendersCollectionAndEntryCountsWhenNotEmpty(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $this->seedEntry((int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = :s', ['s' => 'posts'])['id'], 'hello', 'Hello');

        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringNotContainsString('Create your first collection', $body);
        $this->assertStringContainsString('1</strong> collection', $body);
        $this->assertStringContainsString('1</strong> entry', $body);
        $this->assertStringContainsString('href="/admin/collections"', $body);
    }

    public function testCollectionsLinkIsActiveInTheDashboardNav(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin'));

        $body = (string) $response->getContent();
        $this->assertStringContainsString('href="/admin/collections"', $body);
        $this->assertStringNotContainsString('nav-placeholder', $body);
    }

    public function testDashboardShowsRecentActivityWhenRevisionsAndLoginsExist(): void
    {
        $admin = $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);

        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello world');

        $this->seedRevision($entryId, $admin->id, 'Hello world');

        $this->connection->execute(
            'INSERT INTO login_attempts (email, ip_address, succeeded, cleared_at, created_at)
             VALUES (:email, :ip, 1, NULL, :ts)',
            [
                'email' => 'ada@example.com',
                'ip' => '127.0.0.1',
                'ts' => gmdate('Y-m-d H:i:s'),
            ],
        );

        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Recent activity', $body);
        $this->assertStringContainsString('Recent edits', $body);
        $this->assertStringContainsString('Hello world', $body);
        $this->assertStringContainsString('ada@example.com', $body);
        $this->assertStringContainsString('Recent logins', $body);
    }

    public function testDashboardShowsRecentActivityEmptyStateWhenNothingHasHappened(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Recent activity', $body);
        $this->assertStringContainsString('No activity yet.', $body);
        $this->assertStringNotContainsString('Recent edits', $body);
        $this->assertStringNotContainsString('Recent logins', $body);
    }

    public function testActiveCollectionNameIsSurfacedOnTheEntryList(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);

        $response = $this->kernel->handle(Request::create('/admin/collections/posts'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Now editing', $body);
        $this->assertStringContainsString('<strong>Posts</strong>', $body);
        $this->assertStringContainsString('<code>posts</code>', $body);
    }

    public function testPhase2RoutesAreProtectedByTheAuthGuard(): void
    {
        $paths = [
            '/admin/collections',
            '/admin/collections/new',
            '/admin/collections/posts/edit',
            '/admin/collections/posts',
            '/admin/collections/posts/entries/new',
            '/admin/collections/posts/entries/1/edit',
        ];

        foreach ($paths as $path) {
            $response = $this->kernel->handle(Request::create($path));
            $this->assertSame(
                302,
                $response->getStatusCode(),
                sprintf('Expected redirect for %s when unauthenticated.', $path),
            );
            $this->assertStringContainsString('/admin/login', (string) $response->headers->get('Location'));
        }
    }

    public function testUnknownAdminPathsReturn404(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin/collections/missing/edit'));
        $this->assertSame(404, $response->getStatusCode());

        $response = $this->kernel->handle(Request::create('/admin/collections/missing'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUnsupportedMethodReturns405(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin/collections', 'PUT'));
        $this->assertSame(405, $response->getStatusCode());
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
        return (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = :slug', ['slug' => $slug])['id'];
    }

    private function seedEntry(int $collectionId, string $slug, string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:cid, :slug, :title, :status, :ts, :ts)',
            ['cid' => $collectionId, 'slug' => $slug, 'title' => $title, 'status' => 'published', 'ts' => $now],
        );
        return (int) $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :cid AND slug = :slug',
            ['cid' => $collectionId, 'slug' => $slug],
        )['id'];
    }

    private function seedRevision(int $entryId, ?int $authorId, string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO revisions (entry_id, author_id, payload, created_at)
             VALUES (:eid, :aid, :payload, :ts)',
            [
                'eid' => $entryId,
                'aid' => $authorId,
                'payload' => json_encode(['title' => $title, 'values' => []]),
                'ts' => $now,
            ],
        );
        return (int) $this->connection->fetchOne('SELECT id FROM revisions ORDER BY id DESC LIMIT 1')['id'];
    }

    public function testSuccessfulLoginRedirectsToTheShell(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);

        $response = $this->kernel->handle(Request::create('/admin/login', 'POST', [
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->headers->get('Location'));
    }

    public function testTemplateRenderFailureBecomes500(): void
    {
        @unlink($this->templatesDir . '/admin.twig');

        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(500, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Stack trace', $body);
    }

    private function installTemplates(): void
    {
        file_put_contents(
            $this->templatesDir . '/login.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% block title %}Sign in{% endblock %}\n"
            . "{% block body %}<h1>Sign in to Stead</h1>"
            . "{% if error %}<p role=\"alert\" class=\"error\">{{ error }}</p>{% endif %}"
            . "<form method=\"post\" action=\"/admin/login\">"
            . "{% if redirect %}<input type=\"hidden\" name=\"redirect\" value=\"{{ redirect }}\">{% endif %}"
            . "<label for=\"email\">Email</label><input type=\"email\" id=\"email\" name=\"email\" value=\"{{ email|default('') }}\" required>"
            . "<label for=\"password\">Password</label><input type=\"password\" id=\"password\" name=\"password\" required autocomplete=\"current-password\">"
            . "<button type=\"submit\">Sign in</button></form>{% endblock %}\n",
        );

        file_put_contents(
            $this->templatesDir . '/admin.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% import 'admin/_state.twig' as state %}\n"
            . "{% block title %}Stead admin{% endblock %}\n"
            . "{% block body %}<h1>Stead admin</h1>"
            . "<nav class=\"admin-nav\"><ul>"
            . "<li><a href=\"/admin\">Dashboard</a></li>"
            . "<li><a href=\"/admin/collections\">Collections</a></li>"
            . "</ul></nav>"
            . "<p>Signed in as <strong>{{ user_name }}</strong>.</p>"
            . "{% if collection_count is defined and collection_count > 0 %}"
            . "<section class=\"dashboard-summary\">"
            . "<h2>Dashboard</h2>"
            . "<p><strong>{{ collection_count }}</strong> collection{{ collection_count == 1 ? '' : 's' }}, "
            . "<strong>{{ entry_count }}</strong> entr{{ entry_count == 1 ? 'y' : 'ies' }}.</p>"
            . "</section>"
            . "{% else %}"
            . "{{ state.empty('Create your first collection', null, '/admin/collections/new', 'Create your first collection') }}"
            . "{% endif %}"
            . "<section class=\"recent-activity\">"
            . "<h2>Recent activity</h2>"
            . "{% set has_revisions = recent_revisions is defined and recent_revisions|length > 0 %}"
            . "{% set has_logins = recent_logins is defined and recent_logins|length > 0 %}"
            . "{% if not has_revisions and not has_logins %}"
            . "{{ state.empty('No activity yet.', null) }}"
            . "{% else %}"
            . "{% if has_revisions %}<section class=\"recent-edits\"><h3>Recent edits</h3><ul>"
            . "{% for r in recent_revisions %}<li>{{ r.entry_title|default('Untitled') }}</li>{% endfor %}"
            . "</ul></section>{% endif %}"
            . "{% if has_logins %}<section class=\"recent-logins\"><h3>Recent logins</h3><ul>"
            . "{% for l in recent_logins %}<li>{{ l.email }}</li>{% endfor %}"
            . "</ul></section>{% endif %}"
            . "{% endif %}"
            . "</section>"
            . "<form method=\"post\" action=\"/admin/logout\"><button type=\"submit\">Sign out</button></form>"
            . "{% endblock %}\n",
        );

        $entriesDir = $this->templatesDir . '/admin/entries';
        if (!is_dir($entriesDir)) {
            mkdir($entriesDir, 0775, true);
        }
        file_put_contents(
            $this->templatesDir . '/admin/_state.twig',
            "{% macro empty(title, body, action_url, action_label) %}"
            . "<section class=\"empty-state\">"
            . "{% if title is defined and title %}<h3>{{ title|e }}</h3>{% endif %}"
            . "{% if body is defined and body %}<p>{{ body|e }}</p>{% endif %}"
            . "{% if action_url is defined and action_url %}<p><a class=\"button\" href=\"{{ action_url|e }}\">{{ action_label|default('')|e }}</a></p>{% endif %}"
            . "</section>"
            . "{% endmacro %}\n"
            . "{% macro error(title, body) %}<section class=\"error-state\">"
            . "{% if title is defined and title %}<h3>{{ title|e }}</h3>{% endif %}"
            . "{% if body is defined and body %}<p>{{ body|e }}</p>{% endif %}"
            . "</section>{% endmacro %}\n"
            . "{% macro loading(body) %}<section class=\"loading-state\">{% if body is defined and body %}<p>{{ body|e }}</p>{% endif %}</section>{% endmacro %}\n",
        );
        file_put_contents(
            $entriesDir . '/index.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% block body %}<section class=\"active-collection\">"
            . "<p>Now editing <strong>{{ collection.name|e }}</strong> (<code>{{ collection.slug|e }}</code>)</p>"
            . "</section>{% endblock %}\n",
        );
        file_put_contents(
            $entriesDir . '/form.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% block body %}<section class=\"active-collection\">"
            . "<p>Now editing <strong>{{ collection.name|e }}</strong> (<code>{{ collection.slug|e }}</code>)</p>"
            . "</section>{% endblock %}\n",
        );

        $layoutDir = $this->templatesDir . '/layout';
        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0775, true);
        }
        file_put_contents(
            $layoutDir . '/base.twig',
            "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{% block title %}Stead{% endblock %}</title></head><body>{% block body %}{% endblock %}{% block admin_scripts %}{% endblock %}</body></html>\n",
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