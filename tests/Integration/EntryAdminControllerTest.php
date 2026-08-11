<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end coverage of the entry admin controller: list rendering, create
 * with auto-slug, validation-failure re-render that preserves values, edit
 * with the slug preserved on unrelated edits and regenerated on source
 * changes, and delete.
 */
final class EntryAdminControllerTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $authService;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-entries-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
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
                ],
                'sessions' => ['name' => 'stead_test_entries'],
            ],
        );

        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
        $this->migrator->migrate();

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
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, true, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);
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

    public function testListRendersEntriesWithSlugAndPreview(): void
    {
        $this->signIn();
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $this->seedEntry($collectionId, 'hello', 'Hello, world', 'Body.');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Posts entries', $body);
        $this->assertStringContainsString('<code>hello</code>', $body);
        $this->assertStringContainsString('Hello, world', $body);
        $this->assertStringContainsString('New entry', $body);
    }

    public function testCreateAssignsAutoSlugAndRedirectsToEdit(): void
    {
        $this->signIn();
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/entries/new', 'POST', [
            'fields' => [
                'title' => 'Hello, world',
                'body' => 'First body.',
            ],
        ]));

        $this->assertSame(303, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('/admin/collections/posts/entries/', $location);
        $this->assertStringEndsWith('/edit', $location);

        $row = $this->connection->fetchOne(
            'SELECT slug FROM entries WHERE collection_id = (SELECT id FROM collections WHERE slug = :s)',
            ['s' => 'posts'],
        );
        $this->assertSame('hello-world', $row['slug']);
    }

    public function testCreateReRenderOnValidationFailurePreservesValues(): void
    {
        $this->signIn();
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/entries/new', 'POST', [
            'fields' => [
                'title' => '',
                'body' => 'Body text.',
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('This field is required.', $body);
        $this->assertStringContainsString('Body text.', $body);

        $count = (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM entries')['c']);
        $this->assertSame(0, $count, 'no entry should be persisted on validation failure.');
    }

    public function testEditOnUnrelatedFieldsPreservesTheSlug(): void
    {
        $this->signIn();
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntry(
            (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = :s', ['s' => 'posts'])['id'],
            'stable-title',
            'Stable Title',
            'Body one.',
        );

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/entries/' . $entryId . '/edit', 'POST', [
            'fields' => [
                'title' => 'Stable Title',
                'body' => 'Body two.',
            ],
        ]));

        $this->assertSame(303, $response->getStatusCode());
        $row = $this->connection->fetchOne('SELECT slug FROM entries WHERE id = :id', ['id' => $entryId]);
        $this->assertSame('stable-title', $row['slug']);
    }

    public function testEditOnSlugSourceRegeneratesTheSlug(): void
    {
        $this->signIn();
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntry(
            (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = :s', ['s' => 'posts'])['id'],
            'original',
            'Original',
            'Body.',
        );

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/entries/' . $entryId . '/edit', 'POST', [
            'fields' => [
                'title' => 'Renamed',
                'body' => 'Body.',
            ],
        ]));

        $this->assertSame(303, $response->getStatusCode());
        $row = $this->connection->fetchOne('SELECT slug FROM entries WHERE id = :id', ['id' => $entryId]);
        $this->assertSame('renamed', $row['slug']);
    }

    public function testDeleteRemovesEntryAndRedirectsToList(): void
    {
        $this->signIn();
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
        ]);
        $entryId = $this->seedEntry($collectionId, 'doomed', 'Doomed', '');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/entries/' . $entryId . '/delete', 'POST'));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/collections/posts', $response->headers->get('Location'));
        $this->assertNull($this->connection->fetchOne('SELECT id FROM entries WHERE id = :id', ['id' => $entryId]));
    }

    public function testUnknownCollectionReturns404(): void
    {
        $this->signIn();

        $response = $this->kernel->handle(Request::create('/admin/collections/missing'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testEntryBelongingToDifferentCollectionReturns404(): void
    {
        $this->signIn();
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
        ]);
        $pagesId = $this->seedCollection('pages', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
        ]);
        $entryId = $this->seedEntry($pagesId, 'oops', 'Oops', '');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/entries/' . $entryId . '/edit'));

        $this->assertSame(404, $response->getStatusCode());
    }

    private function seedEntry(int $collectionId, string $slug, string $title, string $body): int
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
            'INSERT INTO entry_values
                 (entry_id, field_key, field_type, value, value_text, value_index, created_at, updated_at)
             VALUES (:eid, :fk, :ft, :v, :vt, :vi, :ts, :ts)',
            ['eid' => $entryId, 'fk' => 'title', 'ft' => 'text', 'v' => $title, 'vt' => $title, 'vi' => '', 'ts' => $now],
        );
        if ($body !== '') {
            $this->connection->execute(
                'INSERT INTO entry_values
                     (entry_id, field_key, field_type, value, value_text, value_index, created_at, updated_at)
                 VALUES (:eid, :fk, :ft, :v, :vt, :vi, :ts, :ts)',
                ['eid' => $entryId, 'fk' => 'body', 'ft' => 'richtext', 'v' => $body, 'vt' => $body, 'vi' => '', 'ts' => $now],
            );
        }
        return $entryId;
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
