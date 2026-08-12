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
use Stead\Media\GdImageTransformer;
use Stead\Media\LocalStorage;
use Stead\Media\MediaRepository;
use Stead\Media\TransformCache;
use Stead\Tests\Integration\Support\JpegFixture;
use Stead\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end coverage of the media library: admin list rendering, upload
 * validation (mime allow-list, size limit, traversal), transform generation
 * and caching, and the public serving route.
 */
final class MediaLibraryTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $authService;
    private UserRepository $users;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-media-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        $this->storageDir = $this->projectRoot . '/storage/media';
        mkdir($this->storageDir, 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->copyDir(__DIR__ . '/../../templates', $this->projectRoot . '/templates');
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
                    'storage' => 'storage/media',
                ],
                'sessions' => ['name' => 'stead_test_media'],
                'media' => ['max_size_bytes' => 1048576],
            ],
        );

        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $this->users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $this->authService = new AuthenticationService($this->users, $sessions, $hasher, $this->store, 3600);

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, new TwigRenderer($this->config));
        $this->kernel = new Kernel($app, $router);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/storage",
            "{$this->projectRoot}/templates",
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/database",
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

    private function signIn(): void
    {
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);
    }

    public function testIndexRendersEmptyStateWithUploadForm(): void
    {
        $this->signIn();

        $response = $this->kernel->handle(Request::create('/admin/media'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Media library', $body);
        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('No files yet', $body);
    }

    public function testUploadCreatesRowAndStoresFile(): void
    {
        $this->signIn();

        $fixture = JpegFixture::create($this->projectRoot, 320, 240);
        $upload = new UploadedFile(
            $fixture,
            'photo.jpg',
            'image/jpeg',
            null,
            true,
        );

        $response = $this->kernel->handle(Request::create(
            '/admin/media',
            'POST',
            [],
            [],
            ['file' => $upload],
        ));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/media', $response->headers->get('Location'));

        $row = $this->connection->fetchOne('SELECT * FROM media ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('photo.jpg', $row['filename']);
        $this->assertSame('image/jpeg', $row['mime_type']);

        $storedPath = $this->storageDir . '/' . $row['path'];
        $this->assertFileExists($storedPath);
        $this->assertSame(filesize($fixture), filesize($storedPath));
    }

    public function testUploadRejectsOversizedFile(): void
    {
        $this->signIn();

        $file = $this->projectRoot . '/large.jpg';
        $header = JpegFixture::HEADER;
        file_put_contents($file, $header . str_repeat("\x00", 2 * 1024 * 1024));

        $upload = new UploadedFile($file, 'large.jpg', 'image/jpeg', null, true);

        $response = $this->kernel->handle(Request::create(
            '/admin/media',
            'POST',
            [],
            [],
            ['file' => $upload],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('File is too large', $body);
        $this->assertNull(
            $this->connection->fetchOne('SELECT id FROM media LIMIT 1'),
            'no media row should be persisted for a rejected upload',
        );
    }

    public function testUploadRejectsDisallowedMimeFromServerSideDetection(): void
    {
        $this->signIn();

        $textPath = $this->projectRoot . '/fake.jpg';
        file_put_contents($textPath, "not actually an image\n");

        $upload = new UploadedFile($textPath, 'fake.jpg', 'image/jpeg', null, true);

        $response = $this->kernel->handle(Request::create(
            '/admin/media',
            'POST',
            [],
            [],
            ['file' => $upload],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('File type not allowed', $body);
    }

    public function testUploadedFileAppearsInLibraryList(): void
    {
        $this->signIn();

        $fixture = JpegFixture::create($this->projectRoot, 200, 150);
        $this->kernel->handle(Request::create('/admin/media', 'POST', [], [], [
            'file' => new UploadedFile($fixture, 'pic.jpg', 'image/jpeg', null, true),
        ]));

        $response = $this->kernel->handle(Request::create('/admin/media'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('pic.jpg', $body);
        $this->assertStringContainsString('image/jpeg', $body);
    }

    public function testServeOriginalReturnsTheStoredFile(): void
    {
        $this->signIn();
        $mediaId = $this->seedMedia('cat.jpg', 'image/jpeg', 'cat.jpg');

        $response = $this->kernel->handle(Request::create('/media/' . $mediaId . '/original'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function testServeTransformGeneratesAndCachesVariant(): void
    {
        $this->signIn();
        $mediaId = $this->seedMedia('tree.jpg', 'image/jpeg', 'tree.jpg');

        $response = $this->kernel->handle(Request::create('/media/' . $mediaId . '/thumb'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        $cached = $this->storageDir . '/' . $mediaId . '/thumb.jpg';
        $this->assertFileExists($cached);
        $firstSize = filesize($cached);

        $response2 = $this->kernel->handle(Request::create('/media/' . $mediaId . '/thumb'));
        $this->assertSame(200, $response2->getStatusCode());
        $this->assertSame($firstSize, filesize($cached), 'second request should reuse the cached variant');
    }

    public function testServeRejectsUnknownSize(): void
    {
        $this->signIn();
        $mediaId = $this->seedMedia('flower.jpg', 'image/jpeg', 'flower.jpg');

        $response = $this->kernel->handle(Request::create('/media/' . $mediaId . '/huge'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testServeRejectsTraversalAttempt(): void
    {
        $this->signIn();
        $mediaId = $this->seedMedia('leaf.jpg', 'image/jpeg', 'leaf.jpg');

        $response = $this->kernel->handle(Request::create('/media/' . $mediaId . '/../secret'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testServeRejectsNonImageTransform(): void
    {
        $this->signIn();
        $mediaId = $this->seedMedia('doc.pdf', 'application/pdf', 'doc.pdf');

        $response = $this->kernel->handle(Request::create('/media/' . $mediaId . '/thumb'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMediaReferencePersistsThroughEntrySave(): void
    {
        $this->signIn();
        $mediaId = $this->seedMedia('hero.jpg', 'image/jpeg', 'hero.jpg');

        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'cover', 'type' => 'media', 'label' => 'Cover'],
        ]);

        $response = $this->kernel->handle(Request::create(
            '/admin/collections/posts/entries/new',
            'POST',
            ['fields' => ['title' => 'Hello', 'cover' => ['id' => $mediaId]]],
        ));
        $this->assertSame(303, $response->getStatusCode(), 'media reference should save successfully');

        $row = $this->connection->fetchOne(
            'SELECT value_json FROM entry_values WHERE field_key = :k AND field_type = :t',
            ['k' => 'cover', 't' => 'media'],
        );
        $this->assertNotNull($row);
        $decoded = json_decode((string) $row['value_json'], true);
        $this->assertSame($mediaId, $decoded['id']);
    }

    public function testMediaFieldRejectsUnknownId(): void
    {
        $this->signIn();
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'cover', 'type' => 'media', 'label' => 'Cover'],
        ]);

        $response = $this->kernel->handle(Request::create(
            '/admin/collections/posts/entries/new',
            'POST',
            ['fields' => ['title' => 'Hello', 'cover' => ['id' => 9999]]],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Selected media item no longer exists', $body);
        $this->assertNull(
            $this->connection->fetchOne('SELECT id FROM entries LIMIT 1'),
            'no entry should be saved when media reference is invalid',
        );
    }

    private function seedMedia(string $filename, string $mime, string $storedFilename): int
    {
        $sourcePath = JpegFixture::create($this->projectRoot, 400, 300, $filename);

        $repository = new MediaRepository($this->connection);
        $storage = new LocalStorage($this->config);
        $media = $repository->create([
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => filesize($sourcePath),
            'path' => 'pending',
            'uploaded_by' => null,
        ]);
        $relative = $storage->writeOriginal($media->id(), $storedFilename, $sourcePath);
        $repository->updatePath($media->id(), $relative);
        return $media->id();
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
