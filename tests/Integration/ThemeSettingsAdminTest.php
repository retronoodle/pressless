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
use Stead\Tests\Support\TestRenderer;
use Symfony\Component\HttpFoundation\Request;

final class ThemeSettingsAdminTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $auth;
    private UserRepository $users;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-theme-settings-admin-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->copyDir(__DIR__ . '/../../templates', $this->projectRoot . '/templates');
        $this->copyDir(__DIR__ . '/../../themes/starter', $this->projectRoot . '/themes/starter');
        $this->dbPath = $this->projectRoot . '/stead.sqlite';

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
                    'theme' => 'themes',
                ],
                'theme' => [
                    'active' => 'starter',
                    'max_zip_bytes' => 10485760,
                ],
                'sessions' => ['name' => 'stead_test_theme_settings'],
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

        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->users->create('eve@example.com', 'Eve Editor', self::PASSWORD, User::ROLE_EDITOR, true);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->rrmdir($this->projectRoot);
    }

    public function testAdminViewsFormPrefilledWithManifestDefaults(): void
    {
        file_put_contents($this->projectRoot . '/themes/starter/theme.json', json_encode([
            'name' => 'Starter',
            'version' => '1.0.0',
            'author' => 'Stead',
            'settings' => [
                ['key' => 'hero_title', 'label' => 'Hero title', 'type' => 'text', 'default' => 'Welcome'],
                ['key' => 'show_sidebar', 'label' => 'Show sidebar', 'type' => 'boolean', 'default' => '1'],
                ['key' => 'accent', 'label' => 'Accent', 'type' => 'color', 'default' => '#abcdef'],
            ],
        ]));

        $this->login('ada@example.com');

        $page = $this->get('/admin/theme-settings');
        $this->assertSame(200, $page->getStatusCode());
        $body = (string) $page->getContent();
        $this->assertStringContainsString('Theme settings', $body);
        $this->assertStringContainsString('name="hero_title"', $body);
        $this->assertStringContainsString('value="Welcome"', $body);
        $this->assertStringContainsString('checked', $body, 'boolean default of 1 pre-ticks the checkbox');
        $this->assertStringContainsString('value="#abcdef"', $body);
    }

    public function testAdminSubmitsAndReloadShowsUpdatedValues(): void
    {
        file_put_contents($this->projectRoot . '/themes/starter/theme.json', json_encode([
            'name' => 'Starter',
            'settings' => [
                ['key' => 'hero_title', 'type' => 'text', 'default' => 'Welcome'],
                ['key' => 'show_sidebar', 'type' => 'boolean', 'default' => '1'],
            ],
        ]));

        $this->login('ada@example.com');

        $response = $this->post('/admin/theme-settings', [
            'hero_title' => 'Greetings',
            'show_sidebar' => '0',
        ]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertStringContainsString('flash=Theme', (string) $response->headers->get('Location'));

        $page = $this->get('/admin/theme-settings');
        $body = (string) $page->getContent();
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('value="Greetings"', $body);

        $row = $this->connection->fetchOne(
            'SELECT setting_key, value FROM theme_settings WHERE theme_slug = :slug AND setting_key = :k',
            ['slug' => 'starter', 'k' => 'hero_title'],
        );
        $this->assertSame('Greetings', $row['value'] ?? null);
    }

    public function testNonAdminIsRejected(): void
    {
        $this->login('eve@example.com');
        $page = $this->get('/admin/theme-settings');
        $this->assertSame(403, $page->getStatusCode());
    }

    public function testEmptyStateWhenActiveThemeHasNoManifest(): void
    {
        $this->login('ada@example.com');
        $page = $this->get('/admin/theme-settings');
        $this->assertSame(200, $page->getStatusCode());
        $body = (string) $page->getContent();
        $this->assertStringContainsString('does not declare any settings', $body);
        $this->assertStringNotContainsString('name="settings[', $body, 'no fields rendered');
    }

    public function testSelectWithStoredValueNotInOptionsFallsBackToDefault(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO theme_settings (theme_slug, setting_key, value, created_at, updated_at)
             VALUES (:slug, :key, :value, :ts, :ts)',
            ['slug' => 'starter', 'key' => 'layout', 'value' => 'stale', 'ts' => $now],
        );
        file_put_contents($this->projectRoot . '/themes/starter/theme.json', json_encode([
            'name' => 'Starter',
            'settings' => [
                ['key' => 'layout', 'type' => 'select', 'options' => ['one', 'two'], 'default' => 'one'],
            ],
        ]));

        $this->login('ada@example.com');
        $page = $this->get('/admin/theme-settings');
        $body = (string) $page->getContent();
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('value="one" selected', $body);
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
        $this->auth->attempt($email, self::PASSWORD);
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
