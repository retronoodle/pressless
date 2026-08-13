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

final class ThemeAdminTest extends TestCase
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
        $this->projectRoot = sys_get_temp_dir() . '/stead-theme-admin-' . bin2hex(random_bytes(4));
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
                'sessions' => ['name' => 'stead_test_themes'],
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

    public function testUploadListsTheNewTheme(): void
    {
        $response = $this->uploadZip($this->zipTheme('alt-theme', $this->starterFiles()));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertStringContainsString('/admin/themes', (string) $response->headers->get('Location'));

        $page = $this->kernel->handle(Request::create('/admin/themes'));
        $body = (string) $page->getContent();
        $this->assertSame(200, $page->getStatusCode(), $body);
        $this->assertStringContainsString('alt-theme', $body);
        $this->assertStringContainsString('Alt Theme', $body);
        $this->assertDirectoryExists($this->projectRoot . '/themes/alt-theme');
    }

    public function testActivateServesPublicPagesAndAssetsFromTheNewTheme(): void
    {
        $this->seedPublishedPost();
        $files = $this->starterFiles();
        $files['base.twig'] = str_replace('theme-starter', 'theme-alt', $files['base.twig']);
        $files['base.twig'] = str_replace('Starter theme', 'Alt theme', $files['base.twig']);
        $files['assets/site.css'] = "body { color: #abc; }\n";

        $this->uploadZip($this->zipTheme('alt-theme', $files));
        $theme = $this->connection->fetchOne('SELECT id FROM themes WHERE slug = :slug', ['slug' => 'alt-theme']);
        $this->assertNotNull($theme);

        $activate = $this->kernel->handle(Request::create(
            '/admin/themes/' . $theme['id'] . '/activate',
            'POST',
        ));
        $this->assertSame(303, $activate->getStatusCode());

        $home = $this->kernel->handle(Request::create('/'));
        $this->assertSame(200, $home->getStatusCode());
        $this->assertStringContainsString('Alt theme', (string) $home->getContent());

        $collection = $this->kernel->handle(Request::create('/posts'));
        $this->assertSame(200, $collection->getStatusCode());
        $this->assertStringContainsString('Alt theme', (string) $collection->getContent());

        $entry = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(200, $entry->getStatusCode());
        $this->assertStringContainsString('Alt theme', (string) $entry->getContent());

        $asset = $this->kernel->handle(Request::create('/assets/site.css'));
        $this->assertSame(200, $asset->getStatusCode());
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $asset);
        $this->assertSame(
            realpath($this->projectRoot . '/themes/alt-theme/assets/site.css'),
            $asset->getFile()->getPathname(),
        );
    }

    public function testTraversalZipIsRejectedAndWritesNothingOutsideThemes(): void
    {
        $path = $this->tempFile('evil.zip');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('evil/base.twig', 'base');
        $zip->addFromString('evil/home.twig', 'home');
        $zip->addFromString('evil/collection.twig', 'collection');
        $zip->addFromString('evil/entry.twig', 'entry');
        $zip->addFromString('../../outside.txt', 'nope');
        $zip->close();

        $response = $this->uploadZip($path);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('unsafe', strtolower((string) $response->getContent()));
        $this->assertFileDoesNotExist($this->projectRoot . '/outside.txt');
        $this->assertDirectoryDoesNotExist($this->projectRoot . '/themes/evil');
    }

    public function testDirectPostCannotDeleteTheActiveTheme(): void
    {
        $active = $this->connection->fetchOne('SELECT id FROM themes WHERE is_active = 1');
        $this->assertNotNull($active);

        $response = $this->kernel->handle(Request::create(
            '/admin/themes/' . $active['id'] . '/delete',
            'POST',
        ));
        $this->assertSame(303, $response->getStatusCode());
        $location = urldecode((string) $response->headers->get('Location'));
        $this->assertStringContainsString('cannot be deleted', $location);

        $still = $this->connection->fetchOne('SELECT id FROM themes WHERE id = :id', ['id' => $active['id']]);
        $this->assertNotNull($still);
        $this->assertDirectoryExists($this->projectRoot . '/themes/starter');
    }

    public function testDeletingAnInactiveThemeRemovesRowAndDirectory(): void
    {
        $this->uploadZip($this->zipTheme('doomed', $this->starterFiles()));
        $this->assertDirectoryExists($this->projectRoot . '/themes/doomed');
        $row = $this->connection->fetchOne('SELECT id FROM themes WHERE slug = :slug', ['slug' => 'doomed']);
        $this->assertNotNull($row);

        $response = $this->kernel->handle(Request::create(
            '/admin/themes/' . $row['id'] . '/delete',
            'POST',
        ));
        $this->assertSame(303, $response->getStatusCode());
        $this->assertNull($this->connection->fetchOne('SELECT id FROM themes WHERE slug = :slug', ['slug' => 'doomed']));
        $this->assertDirectoryDoesNotExist($this->projectRoot . '/themes/doomed');
    }

    public function testMissingActiveThemeDirectoryFallsBackWithoutAServerError(): void
    {
        $this->uploadZip($this->zipTheme('alt-theme', $this->starterFiles([
            'base.twig' => str_replace('Starter theme', 'Alt theme', $this->starterFiles()['base.twig']),
        ])));
        $theme = $this->connection->fetchOne('SELECT id FROM themes WHERE slug = :slug', ['slug' => 'alt-theme']);
        $this->assertNotNull($theme);
        $this->kernel->handle(Request::create('/admin/themes/' . $theme['id'] . '/activate', 'POST'));

        rename($this->projectRoot . '/themes/alt-theme', $this->projectRoot . '/themes/alt-theme-missing');

        $response = $this->kernel->handle(Request::create('/'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Starter theme', (string) $response->getContent());
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function starterFiles(array $overrides = []): array
    {
        $dir = $this->projectRoot . '/themes/starter';
        $files = [
            'base.twig' => (string) file_get_contents($dir . '/base.twig'),
            'home.twig' => (string) file_get_contents($dir . '/home.twig'),
            'collection.twig' => (string) file_get_contents($dir . '/collection.twig'),
            'entry.twig' => (string) file_get_contents($dir . '/entry.twig'),
            'assets/site.css' => (string) file_get_contents($dir . '/assets/site.css'),
        ];
        return array_merge($files, $overrides);
    }

    /**
     * @param array<string, string> $files
     */
    private function zipTheme(string $folder, array $files): string
    {
        $path = $this->tempFile($folder . '.zip');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addEmptyDir($folder);
        foreach ($files as $name => $contents) {
            $zip->addFromString($folder . '/' . $name, $contents);
        }
        $zip->close();
        return $path;
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

    private function seedPublishedPost(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            [
                'slug' => 'posts',
                'name' => 'Posts',
                'schema' => json_encode(['fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Title']]]),
                'ts' => $now,
            ],
        );
        $collectionId = (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        )['id'];
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:cid, :slug, :title, :status, :ts, :ts)',
            ['cid' => $collectionId, 'slug' => 'hello', 'title' => 'Hello', 'status' => 'published', 'ts' => $now],
        );
        $entryId = (int) $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :cid AND slug = :slug',
            ['cid' => $collectionId, 'slug' => 'hello'],
        )['id'];
        $this->connection->execute(
            'INSERT INTO entry_values (entry_id, field_key, field_type, value, value_text, created_at, updated_at)
             VALUES (:eid, :key, :type, :value, :value_text, :ts, :ts)',
            [
                'eid' => $entryId,
                'key' => 'title',
                'type' => 'text',
                'value' => 'Hello',
                'value_text' => 'Hello',
                'ts' => $now,
            ],
        );
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
