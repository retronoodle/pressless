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
use Pressless\Console\ServePreflight;
use Pressless\Database\Connection;
use Pressless\Http\Kernel;
use Pressless\Http\Routes;
use Pressless\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end smoke test for the evaluator path: fresh setup → migrate → seed
 * → unauthenticated redirect → login → empty admin shell.
 *
 * Runs against MySQL/MariaDB when DB_HOST is set; otherwise it falls back to
 * SQLite so the smoke test still exercises the same code paths locally and in
 * CI environments without a MySQL server.
 */
final class InstallToLoginSmokeTest extends TestCase
{
    private string $projectRoot;
    private string $dbPath = '';
    private Configuration $config;
    private Connection $connection;

    protected function setUp(): void
    {
        // The smoke test can be steered at a real MySQL via DB_HOST, but the
        // default is SQLite so it does not require external services. Clear
        // any leftover DB_HOST so a developer's shell does not break it.
        putenv('DB_HOST');
        $_ENV['DB_HOST'] = '';

        $this->projectRoot = sys_get_temp_dir() . '/pressless-smoke-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/templates', 0775, true);
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);

        // Copy the project's real templates into the test project root so
        // Configuration::path() (which prepends the project root) finds them.
        $this->copyDir(__DIR__ . '/../../templates', $this->projectRoot . '/templates');

        // Pick MySQL when one is configured, otherwise SQLite so the path is
        // exercised without external services.
        $dbHost = getenv('DB_HOST');
        if (is_string($dbHost) && $dbHost !== '') {
            foreach (glob(__DIR__ . '/../../database/migrations/*.mysql.sql') ?: [] as $src) {
                copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
            }

            $this->config = new Configuration(
                $this->projectRoot,
                'development',
                [
                    'app' => ['debug' => false],
                    'database' => [
                        'connection' => 'mysql',
                        'host' => $dbHost,
                        'port' => (int) (getenv('DB_PORT') ?: 3306),
                        'database' => (string) (getenv('DB_DATABASE') ?: 'pressless_smoke'),
                        'username' => (string) (getenv('DB_USERNAME') ?: 'root'),
                        'password' => (string) (getenv('DB_PASSWORD') ?: ''),
                        'charset' => 'utf8mb4',
                    ],
                    'paths' => [
                        'migrations' => 'database/migrations',
                        'templates' => 'templates',
                        'cache' => 'var/cache',
                        'log' => 'var/log',
                    ],
                    'sessions' => ['name' => 'pressless_smoke'],
                    'logging' => ['level' => 'error', 'file' => 'smoke.log'],
                ],
            );
        } else {
            foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
                copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
            }
            $this->dbPath = $this->projectRoot . '/pressless.sqlite';

            $this->config = new Configuration(
                $this->projectRoot,
                'development',
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
                    'sessions' => ['name' => 'pressless_smoke'],
                    'logging' => ['level' => 'error', 'file' => 'smoke.log'],
                ],
            );
        }

        $this->connection = new Connection($this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        if ($this->dbPath !== '') {
            @unlink($this->dbPath);
        }
        foreach ([
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/database",
            "{$this->projectRoot}/templates",
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

    public function testFreshSetupToLoginFlow(): void
    {
        // 1. Fresh reset (the `--fresh` preflight)
        $preflight = new ServePreflight($this->connection, $this->config);
        $result = $preflight->run(
            fresh: true,
            seed: true,
            server: ['host' => '127.0.0.1', 'port' => 8000],
        );

        $this->assertSame(1, $this->userCount(), 'Seeder must create exactly one administrator.');
        $this->assertSame(2, $this->collectionCount(), 'Seeder must create the sample collections.');
        $this->assertNotNull($result['seed']['admin_email']);
        $this->assertNotNull($result['seed']['admin_password']);

        $adminEmail = (string) $result['seed']['admin_email'];
        $adminPassword = (string) $result['seed']['admin_password'];

        // 2. Wire up the application as it boots in `bin/serve`
        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->makeStore(), new TwigRenderer($this->config));
        $kernel = new Kernel($app, $router);

        // 3. Unauthenticated request to /admin must redirect to login (no shell markup).
        $redirect = $kernel->handle(Request::create('/admin'));
        $this->assertSame(302, $redirect->getStatusCode());
        $this->assertStringContainsString('/admin/login', (string) $redirect->headers->get('Location'));

        // 4. GET /admin/login renders the login form.
        $loginPage = $kernel->handle(Request::create('/admin/login'));
        $this->assertSame(200, $loginPage->getStatusCode());
        $this->assertStringContainsString('Sign in to Pressless', (string) $loginPage->getContent());

        // 5. POST /admin/login with valid credentials redirects to /admin.
        $authResponse = $kernel->handle(Request::create('/admin/login', 'POST', [
            'email' => $adminEmail,
            'password' => $adminPassword,
        ]));
        $this->assertSame(302, $authResponse->getStatusCode());
        $this->assertSame('/admin', $authResponse->headers->get('Location'));

        // 6. The seeded administrator can authenticate via the real auth path.
        $hasher = new PasswordHasher();
        $users = new UserRepository($this->connection, $hasher);
        $user = $users->findByEmail($adminEmail);
        $this->assertNotNull($user);
        $this->assertTrue($hasher->verify($adminPassword, $user->passwordHash));
    }

    public function testInvalidCredentialsDoNotLeakExistence(): void
    {
        (new ServePreflight($this->connection, $this->config))->run(
            fresh: true,
            seed: true,
            server: ['host' => '127.0.0.1', 'port' => 8000],
        );
        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->makeStore(), new TwigRenderer($this->config));
        $kernel = new Kernel($app, $router);

        // Submit the same email for both failure modes so the bodies can be
        // compared byte-for-byte without the prefilled email making them differ.
        $unknown = $kernel->handle(Request::create('/admin/login', 'POST', [
            'email' => 'same@example.com',
            'password' => 'whatever',
        ]));
        $wrong = $kernel->handle(Request::create('/admin/login', 'POST', [
            'email' => 'same@example.com',
            'password' => 'wrong',
        ]));

        $this->assertSame(401, $unknown->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $wrong->getStatusCode());
        $this->assertSame((string) $unknown->getContent(), (string) $wrong->getContent());

        $body = (string) $unknown->getContent();
        $this->assertStringContainsString('do not match our records', $body);
        $this->assertStringNotContainsString('inactive', strtolower($body));
        $this->assertStringNotContainsString('no such user', strtolower($body));
    }

    /**
     * End-to-end evaluator path: fresh reset → migrations → seed → login →
     * create a collection → edit its field set → create an entry → see the
     * entry in the list with the auto-generated slug → delete it.
     */
    public function testEvaluatorPathFromFreshResetToEntryDelete(): void
    {
        $result = (new ServePreflight($this->connection, $this->config))->run(
            fresh: true,
            seed: true,
            server: ['host' => '127.0.0.1', 'port' => 8000],
        );

        $adminEmail = (string) $result['seed']['admin_email'];
        $adminPassword = (string) $result['seed']['admin_password'];

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->makeStore(), new TwigRenderer($this->config));
        $kernel = new Kernel($app, $router);

        // Login.
        $login = $kernel->handle(Request::create('/admin/login', 'POST', [
            'email' => $adminEmail,
            'password' => $adminPassword,
        ]));
        $this->assertSame(302, $login->getStatusCode());

        // Create a collection through the admin surface.
        $create = $kernel->handle(Request::create('/admin/collections/new', 'POST', [
            'slug' => 'notes',
            'name' => 'Notes',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => '1'],
                ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
            ],
        ]));
        $this->assertSame(303, $create->getStatusCode());

        // Edit the collection's field set (drop the body, add a summary).
        $update = $kernel->handle(Request::create('/admin/collections/notes/edit', 'POST', [
            'slug' => 'notes',
            'name' => 'Notes',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => '1'],
                ['key' => 'summary', 'type' => 'text', 'label' => 'Summary'],
            ],
        ]));
        $this->assertSame(303, $update->getStatusCode());

        $schemaRow = $this->connection->fetchOne(
            'SELECT schema_definition FROM collections WHERE slug = :slug',
            ['slug' => 'notes'],
        );
        $schema = json_decode((string) $schemaRow['schema_definition'], true);
        $fieldKeys = array_column($schema['fields'], 'key');
        $this->assertSame(['title', 'summary'], $fieldKeys);

        // Create an entry through the admin surface.
        $entryCreate = $kernel->handle(Request::create('/admin/collections/notes/entries/new', 'POST', [
            'fields' => [
                'title' => 'Smoke test note',
                'summary' => 'End-to-end evaluator path.',
            ],
        ]));
        $this->assertSame(303, $entryCreate->getStatusCode());

        $entryRow = $this->connection->fetchOne(
            'SELECT id, slug FROM entries WHERE collection_id = (SELECT id FROM collections WHERE slug = :slug)',
            ['slug' => 'notes'],
        );
        $this->assertNotNull($entryRow);
        $this->assertSame('smoke-test-note', $entryRow['slug']);
        $entryId = (int) $entryRow['id'];

        // See the entry in the list with the auto-slug.
        $list = $kernel->handle(Request::create('/admin/collections/notes'));
        $this->assertSame(200, $list->getStatusCode());
        $body = (string) $list->getContent();
        $this->assertStringContainsString('code>smoke-test-note</code>', $body);
        $this->assertStringContainsString('Smoke test note', $body);

        // Delete the entry.
        $delete = $kernel->handle(Request::create('/admin/collections/notes/entries/' . $entryId . '/delete', 'POST'));
        $this->assertSame(303, $delete->getStatusCode());
        $this->assertNull($this->connection->fetchOne('SELECT id FROM entries WHERE id = :id', ['id' => $entryId]));
    }

    private function makeStore(): ArraySessionStore
    {
        return new ArraySessionStore(new SessionRepository($this->connection));
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

    private function userCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    private function collectionCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections')['c'] ?? 0);
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