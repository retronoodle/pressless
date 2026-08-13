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
use Stead\Update\InstalledVersion;
use Stead\Update\ReleaseEndpointClient;
use Stead\Update\UpdateCheckCache;
use Stead\Update\UpdateChecker;
use Stead\Tests\Support\TestRenderer;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end coverage of the admin update-notice and update-instructions
 * surfaces. These are the smoke tests called out in tasks.md (5.2 / 5.3)
 * but executed in-process so they run as part of CI rather than needing
 * a real release endpoint and a second install to point at it.
 *
 * The fake release server (PHP CLI built-in server) stands in for the
 * website's release endpoint. The cases mirror the smoke-test bullet
 * points one-for-one:
 *
 *   - newer version available → dashboard shows banner, /admin/update
 *     shows the instructions page with the right version + URL
 *   - endpoint unreachable → checker fails closed, dashboard renders
 *     without any update banner, no exception surfaces
 */
final class UpdateAdminTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private string $dbPath;
    private string $cacheRoot;
    private string $templatesDir;
    private Kernel $kernel;
    private Configuration $config;
    private Connection $connection;
    private UserRepository $users;
    private ArraySessionStore $store;
    private AuthenticationService $authService;
    private ?FakeReleaseServer $server = null;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-update-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/src', 0775, true);
        // Stead's ProjectRoot::resolve walks up looking for composer.json
        // + src/ at the same level; the temp dir needs both to be
        // recognised as a Stead root. The composer.json can be empty —
        // it's only checked for existence.
        file_put_contents($this->projectRoot . '/composer.json', '{}');
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->dbPath = $this->projectRoot . '/var/stead.sqlite';
        $this->cacheRoot = $this->projectRoot . '/var/cache';

        // The integration test runs Routes::createWithStore, which builds
        // its own UpdateChecker from config. To exercise the live code
        // path we configure update.api_base_url to point at the fake
        // server below and seed a VERSION file.
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
                'update' => [
                    'github_repo' => 'stead/test',
                    'api_base_url' => 'http://placeholder.invalid/',
                    'check_interval_hours' => 24,
                    'timeout_seconds' => 1,
                ],
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
        $this->server?->stop();
        @unlink($this->projectRoot . '/VERSION');
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

    public function testAdminDashboardShowsUpdateNoticeWhenNewerVersionExists(): void
    {
        $this->server = new FakeReleaseServer(
            responseStatus: 200,
            responseBody: json_encode([
                'tag_name' => 'v9.9.9',
                'assets' => [
                    ['name' => 'stead-9.9.9.zip', 'browser_download_url' => 'https://example.test/stead-9.9.9.zip'],
                ],
                'zipball_url' => 'https://api.github.com/repos/stead/test/zipball/v9.9.9',
            ]),
        );
        file_put_contents($this->projectRoot . '/VERSION', "1.0.0\n");

        $this->retargetUpdateSource($this->server->url());
        $this->logInAsAdmin();

        $response = $this->kernel->handle(Request::create('/admin'));
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Update available', $body);
        $this->assertStringContainsString('9.9.9', $body);
        $this->assertStringContainsString('1.0.0', $body);
        $this->assertStringContainsString('View update instructions', $body);
        $this->assertStringContainsString('href="/admin/update"', $body);
    }

    public function testAdminUpdatePageShowsManualInstructionsWhenNewerVersionExists(): void
    {
        $this->server = new FakeReleaseServer(
            responseStatus: 200,
            responseBody: json_encode([
                'tag_name' => 'v9.9.9',
                'assets' => [
                    ['name' => 'stead-9.9.9.zip', 'browser_download_url' => 'https://example.test/stead-9.9.9.zip'],
                ],
                'zipball_url' => 'https://api.github.com/repos/stead/test/zipball/v9.9.9',
            ]),
        );
        file_put_contents($this->projectRoot . '/VERSION', "1.0.0\n");

        $this->retargetUpdateSource($this->server->url());
        $this->logInAsAdmin();

        $response = $this->kernel->handle(Request::create('/admin/update'));
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Update Stead', $body);
        $this->assertStringContainsString('Download Stead 9.9.9', $body);
        $this->assertStringContainsString('https://example.test/stead-9.9.9.zip', $body);
        $this->assertStringContainsString('php bin/migrate', $body);
    }

    public function testDashboardDoesNotShowBannerWhenEndpointUnreachable(): void
    {
        // No fake server started → api_base_url points at a closed port
        // and the checker must fail closed.
        file_put_contents($this->projectRoot . '/VERSION', "1.0.0\n");
        $this->retargetUpdateSource('http://127.0.0.1:1/');
        $this->logInAsAdmin();

        $response = $this->kernel->handle(Request::create('/admin'));
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Update available', $body);
        $this->assertStringNotContainsString('View update instructions', $body);
    }

    public function testDashboardDoesNotShowBannerWhenEndpointReturnsError(): void
    {
        $this->server = new FakeReleaseServer(
            responseStatus: 503,
            responseBody: '{"error":"down"}',
        );
        file_put_contents($this->projectRoot . '/VERSION', "1.0.0\n");
        $this->retargetUpdateSource($this->server->url());
        $this->logInAsAdmin();

        $response = $this->kernel->handle(Request::create('/admin'));
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Update available', $body);
    }

    public function testDashboardDoesNotShowBannerWhenInstalledVersionEqualsLatest(): void
    {
        $this->server = new FakeReleaseServer(
            responseStatus: 200,
            responseBody: json_encode([
                'tag_name' => 'v1.0.0',
                'assets' => [
                    ['name' => 'stead-1.0.0.zip', 'browser_download_url' => 'https://example.test/stead-1.0.0.zip'],
                ],
                'zipball_url' => 'https://api.github.com/repos/stead/test/zipball/v1.0.0',
            ]),
        );
        file_put_contents($this->projectRoot . '/VERSION', "1.0.0\n");
        $this->retargetUpdateSource($this->server->url());
        $this->logInAsAdmin();

        $response = $this->kernel->handle(Request::create('/admin'));
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Update available', $body);
    }

    /**
     * Re-points the cached update checker at a different release API
     * base URL by rebuilding the kernel with a fresh Configuration. The
     * routes were already wired with their own UpdateChecker in setUp();
     * this is the cleanest way to swap the api_base_url without
     * monkey-patching the route graph.
     */
    private function retargetUpdateSource(string $url): void
    {
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
                'update' => [
                    'github_repo' => 'stead/test',
                    'api_base_url' => $url,
                    'check_interval_hours' => 24,
                    'timeout_seconds' => 2,
                ],
            ],
        );

        // Build a fresh kernel/router pointed at the new api_base_url.
        // The cache stays on disk between requests, so we explicitly clear
        // it so the first request after retargeting re-queries.
        $cache = new UpdateCheckCache($this->cacheRoot);
        $cache->clear();

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, TestRenderer::twig($this->config, $this->connection));
        $this->kernel = new Kernel($app, $router);
    }

    private function logInAsAdmin(): void
    {
        $this->users->create('admin@example.test', 'Admin', self::PASSWORD, User::ROLE_ADMIN);
        $user = $this->authService->attempt('admin@example.test', self::PASSWORD);
        $this->assertNotNull($user, 'Login attempt should succeed for an admin user.');
    }

    private function installTemplates(): void
    {
        file_put_contents(
            $this->templatesDir . '/admin.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% block body %}<header><nav>"
            . "<a href=\"/admin\">Dashboard</a>"
            . "</nav></header>"
            . "<main>"
            . "{% if update_notice is defined and update_notice %}"
            . "<section class=\"update-notice\" role=\"status\">"
            . "<h2>Update available</h2>"
            . "<p>A newer version of Stead is available: "
            . "<strong>{{ update_notice.latest }}</strong> (you are running {{ update_notice.installed }}).</p>"
            . "<p><a class=\"button\" href=\"/admin/update\">View update instructions</a></p>"
            . "</section>"
            . "{% endif %}"
            . "</main>{% endblock %}\n",
        );

        $adminDir = $this->templatesDir . '/admin';
        if (!is_dir($adminDir)) {
            mkdir($adminDir, 0775, true);
        }
        file_put_contents(
            $adminDir . '/update.twig',
            "{% extends 'layout/base.twig' %}\n"
            . "{% block body %}<h1>Update Stead</h1>"
            . "{% if available %}"
            . "<p>You are running <strong>{{ installed_version }}</strong>. "
            . "Version <strong>{{ latest_version }}</strong> is available.</p>"
            . "<p><a class=\"button\" href=\"{{ download_url }}\">Download Stead {{ latest_version }}</a></p>"
            . "<ol class=\"update-steps\">"
            . "<li>Download the new release ZIP using the link above.</li>"
            . "<li>Run <code>php bin/migrate</code>.</li>"
            . "</ol>"
            . "{% else %}"
            . "<p>No update is currently available.</p>"
            . "{% endif %}"
            . "{% endblock %}\n",
        );

        $layoutDir = $this->templatesDir . '/layout';
        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0775, true);
        }
        file_put_contents(
            $layoutDir . '/base.twig',
            "<!doctype html><html><head><title>{% block title %}Stead{% endblock %}</title></head>"
            . "<body>{% block body %}{% endblock %}</body></html>\n",
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

/**
 * One-shot HTTP server used by these tests. Lives in this file rather
 * than the unit test so each test class is self-contained.
 */
final class FakeReleaseServer
{
    /** @var resource|null */
    private $process = null;
    private string $url;
    private string $routerPath;

    public function __construct(int $responseStatus, string $responseBody)
    {
        $this->routerPath = '';
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('Could not pick a port for the fake release server.');
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $this->url = 'http://' . $name . '/';

        $routerPath = tempnam(sys_get_temp_dir(), 'stead-fake-release-');
        if ($routerPath === false) {
            throw new \RuntimeException('Could not write router script for fake release server.');
        }
        file_put_contents($routerPath, <<<'PHP'
<?php
header('Content-Type: application/json');
PHP);
        file_put_contents(
            $routerPath,
            "\nhttp_response_code({$responseStatus});\necho " . var_export($responseBody, true) . ";\n",
            FILE_APPEND,
        );

        $proc = proc_open(
            sprintf('php -S %s %s', escapeshellarg($name), escapeshellarg($routerPath)),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($proc)) {
            @unlink($routerPath);
            throw new \RuntimeException('Could not start fake release server.');
        }
        $this->process = $proc;
        $this->routerPath = $routerPath;

        $deadline = microtime(true) + 2.0;
        do {
            $probe = @curl_init($this->url);
            if ($probe !== false) {
                curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
                curl_exec($probe);
                $status = (int) curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
                curl_close($probe);
                if ($status === $responseStatus) {
                    return;
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process, 9);
            @proc_close($this->process);
        }
        if ($this->routerPath !== '' && is_file($this->routerPath)) {
            @unlink($this->routerPath);
        }
    }
}
