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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class ThemeSettingsLifecycleTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $auth;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-theme-settings-lifecycle-' . bin2hex(random_bytes(4));
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
                'sessions' => ['name' => 'stead_test_theme_lifecycle'],
            ],
        );

        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $this->auth = new AuthenticationService($users, $sessions, $hasher, $this->store, 3600);

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, TestRenderer::twig($this->config, $this->connection));
        $this->kernel = new Kernel($app, $router);

        $users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->auth->attempt('ada@example.com', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->rrmdir($this->projectRoot);
    }

    public function testDormantValueSurvivesManifestKeyRemovalAndReappearsWhenRestored(): void
    {
        $manifestWithSetting = json_encode([
            'name' => 'Lifecycle',
            'settings' => [
                ['key' => 'hero_title', 'type' => 'text', 'default' => 'Welcome'],
            ],
        ]);
        $manifestWithoutSetting = json_encode([
            'name' => 'Lifecycle',
            'settings' => [],
        ]);

        // 1. Install `lifecycle` (settings include hero_title) and a sibling
        // `alt` theme (no settings) so we can switch the active flag
        // around without losing our test fixture.
        $this->uploadZip($this->zipTheme('lifecycle', $manifestWithSetting));
        $this->uploadZip($this->zipTheme('alt', json_encode(['name' => 'Alt'])));
        $altId = (int) $this->row('alt')['id'];
        $lifecycleFirstId = (int) $this->row('lifecycle')['id'];

        $this->activate($altId);
        $this->activate($lifecycleFirstId);

        // Store a value on the active lifecycle slug.
        $saved = $this->post('/admin/theme-settings', ['hero_title' => 'Configured value']);
        $this->assertSame(303, $saved->getStatusCode());
        $this->assertSame('Configured value', $this->rowSetting('lifecycle', 'hero_title'));

        // 2. Move `alt` back to active and delete `lifecycle`. The
        // stored setting row must survive the delete.
        $this->activate($altId);
        $this->delete($lifecycleFirstId);
        $this->assertNull($this->row('lifecycle'));
        $this->assertDirectoryDoesNotExist($this->projectRoot . '/themes/lifecycle');
        $this->assertSame('Configured value', $this->rowSetting('lifecycle', 'hero_title'), 'value retained dormant');

        // 3. Re-upload `lifecycle` with the key removed from the
        // manifest. The dormant value should be invisible to both the
        // admin form and the rendered templates.
        $this->uploadZip($this->zipTheme('lifecycle', $manifestWithoutSetting));
        $this->assertSame('Configured value', $this->rowSetting('lifecycle', 'hero_title'), 'still retained');

        $this->activate($altId);
        $this->activate((int) $this->row('lifecycle')['id']);

        $formBody = (string) $this->get('/admin/theme-settings')->getContent();
        $this->assertStringContainsString('does not declare any settings', $formBody);
        $this->assertSame('', $this->renderGlobal(), 'dormant key renders empty in Twig global');

        // 4. Re-upload `lifecycle` with the key restored. The dormant
        // value should reappear on the form and in the global.
        $this->activate($altId);
        $this->delete((int) $this->row('lifecycle')['id']);

        $this->uploadZip($this->zipTheme('lifecycle', $manifestWithSetting));
        $this->assertSame('Configured value', $this->rowSetting('lifecycle', 'hero_title'), 'still retained');

        $this->activate($altId);
        $this->activate((int) $this->row('lifecycle')['id']);

        $reloadedForm = (string) $this->get('/admin/theme-settings')->getContent();
        $this->assertStringContainsString('value="Configured value"', $reloadedForm);

        $this->assertSame('Configured value', $this->renderGlobal());
    }

    private function activate(int $id): void
    {
        $response = $this->kernel->handle(Request::create('/admin/themes/' . $id . '/activate', 'POST'));
        $this->assertSame(303, $response->getStatusCode(), 'activating theme ' . $id);
    }

    private function delete(int $id): void
    {
        $response = $this->kernel->handle(Request::create('/admin/themes/' . $id . '/delete', 'POST'));
        $this->assertSame(303, $response->getStatusCode(), 'deleting theme ' . $id);
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

    private function uploadZip(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create(
            '/admin/themes',
            'POST',
            [],
            [],
            ['file' => new UploadedFile($path, basename($path), 'application/zip', null, true)],
        ));
    }

    private function renderGlobal(): string
    {
        $body = (string) $this->get('/')->getContent();
        $start = strpos($body, 'GLOBAL[');
        if ($start === false) {
            return $body;
        }
        $end = strpos($body, ']', $start);
        return substr($body, $start + 7, $end - $start - 7);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $slug): ?array
    {
        return $this->connection->fetchOne('SELECT * FROM themes WHERE slug = :slug', ['slug' => $slug]);
    }

    private function rowSetting(string $slug, string $key): ?string
    {
        $row = $this->connection->fetchOne(
            'SELECT value FROM theme_settings WHERE theme_slug = :slug AND setting_key = :key',
            ['slug' => $slug, 'key' => $key],
        );
        return $row === null ? null : (string) $row['value'];
    }

    private function zipTheme(string $folder, string $manifestJson): string
    {
        $path = $this->tempFile($folder . '-' . bin2hex(random_bytes(4)) . '.zip');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString($folder . '/base.twig', 'base');
        $zip->addFromString($folder . '/home.twig', 'GLOBAL[{{ theme_settings.hero_title|default("") }}]');
        $zip->addFromString($folder . '/collection.twig', 'collection');
        $zip->addFromString($folder . '/entry.twig', 'entry');
        $zip->addFromString($folder . '/theme.json', $manifestJson);
        $zip->close();
        return $path;
    }

    private function tempFile(string $name): string
    {
        $path = $this->projectRoot . '/' . $name;
        $this->tempFiles[] = $path;
        return $path;
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
