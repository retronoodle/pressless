<?php

declare(strict_types=1);

namespace Pressless\Tests\Integration;

use Pressless\Auth\ArraySessionStore;
use Pressless\Auth\AuthenticationService;
use Pressless\Auth\PasswordHasher;
use Pressless\Auth\SessionRepository;
use Pressless\Auth\UserRepository;
use Pressless\Bootstrap\Application;
use Pressless\Config\Configuration;
use Pressless\Database\Connection;
use Pressless\Database\Migrator;
use Pressless\Http\Kernel;
use Pressless\Http\Routes;
use Pressless\View\TwigRenderer;
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
        $this->projectRoot = sys_get_temp_dir() . '/pressless-shell-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        copy(
            __DIR__ . '/../../database/migrations/20260811000001_initial_schema.sqlite.sql',
            $this->projectRoot . '/database/migrations/20260811000001_initial_schema.sqlite.sql',
        );
        $this->dbPath = $this->projectRoot . '/var/pressless.sqlite';

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
                'sessions' => ['name' => 'pressless_session'],
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
        $router = Routes::createWithStore($app, $this->store, new TwigRenderer($this->config));
        $this->kernel = new Kernel($app, $router);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/templates/login.twig",
            "{$this->projectRoot}/templates/admin.twig",
            "{$this->projectRoot}/templates/layout/base.twig",
            "{$this->projectRoot}/database/migrations/20260811000001_initial_schema.sqlite.sql",
            "{$this->projectRoot}/var/cache/twig",
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        @rmdir("{$this->projectRoot}/templates/layout");
        @rmdir("{$this->projectRoot}/templates");
        @rmdir("{$this->projectRoot}/database/migrations");
        @rmdir("{$this->projectRoot}/database");
        @rmdir("{$this->projectRoot}/var/cache");
        @rmdir("{$this->projectRoot}/var/log");
        @rmdir("{$this->projectRoot}/var");
        @rmdir($this->projectRoot);
    }

    public function testLoginPageRendersTheLoginForm(): void
    {
        $response = $this->kernel->handle(Request::create('/admin/login'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Sign in to Pressless', $body);
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
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, true, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);

        $response = $this->kernel->handle(Request::create('/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Pressless admin', $body);
        $this->assertStringContainsString('No collections yet', $body);
        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringContainsString('action="/admin/logout"', $body);
    }

    public function testSuccessfulLoginRedirectsToTheShell(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, true, true);

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

        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, true, true);
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
            . "{% block body %}<h1>Sign in to Pressless</h1>"
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
            . "{% block title %}Pressless admin{% endblock %}\n"
            . "{% block body %}<h1>Pressless admin</h1>"
            . "<p>Signed in as <strong>{{ user_name }}</strong>.</p>"
            . "<section class=\"empty-state\"><h2>No collections yet</h2><p>Phase 1 placeholder.</p></section>"
            . "<form method=\"post\" action=\"/admin/logout\"><button type=\"submit\">Sign out</button></form>"
            . "{% endblock %}\n",
        );

        $layoutDir = $this->templatesDir . '/layout';
        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0775, true);
        }
        file_put_contents(
            $layoutDir . '/base.twig',
            "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{% block title %}Pressless{% endblock %}</title></head><body>{% block body %}{% endblock %}</body></html>\n",
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