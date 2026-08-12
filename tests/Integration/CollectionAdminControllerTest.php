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
use Stead\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end coverage of the collection admin controller: list rendering,
 * create, validation-failure re-render, edit (including a schema change that
 * drops a field), schema-change rollback on a failing helper call, and
 * delete.
 */
final class CollectionAdminControllerTest extends TestCase
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
        $this->projectRoot = sys_get_temp_dir() . '/stead-collections-' . bin2hex(random_bytes(4));
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
                'sessions' => ['name' => 'stead_test_collections'],
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
        $this->users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $this->authService->attempt('ada@example.com', self::PASSWORD);
    }

    public function testListRendersExistingCollections(): void
    {
        $this->signIn();
        $this->seedCollection('posts', 'Posts');

        $response = $this->kernel->handle(Request::create('/admin/collections'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Collections', $body);
        $this->assertStringContainsString('<code>posts</code>', $body);
        $this->assertStringContainsString('New collection', $body);
    }

    public function testCreatePersistsCollectionAndRedirectsToEntryList(): void
    {
        $this->signIn();

        $response = $this->kernel->handle(Request::create('/admin/collections/new', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => '1'],
                ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
            ],
        ]));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/collections/posts', $response->headers->get('Location'));

        $row = $this->connection->fetchOne(
            'SELECT slug, name, schema_definition FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        );
        $this->assertNotNull($row);
        $this->assertSame('Posts', $row['name']);
        $schema = json_decode($row['schema_definition'], true);
        $this->assertCount(2, $schema['fields']);
        $this->assertSame('title', $schema['fields'][0]['key']);

        $logCount = (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM schema_change_log')['c'] ?? 0);
        $this->assertSame(1, $logCount, 'first save should record a schema-change log row.');
    }

    public function testCreateReRenderOnValidationFailurePreservesInput(): void
    {
        $this->signIn();

        $response = $this->kernel->handle(Request::create('/admin/collections/new', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['key' => 'title', 'type' => 'text', 'label' => 'Duplicate'],
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Duplicate field key.', $body);
        $this->assertStringContainsString('value="posts"', $body);
        $this->assertStringContainsString('value="Posts"', $body);

        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections');
        $this->assertSame(0, (int) $row['c'], 'no collection should be persisted on validation failure.');
    }

    public function testEditWithSchemaChangeRemovesDroppedFieldValues(): void
    {
        $this->signIn();
        $collectionId = $this->seedCollection('posts', 'Posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello');
        $this->seedEntryValue($entryId, 'title', 'text', 'Hello');
        $this->seedEntryValue($entryId, 'body', 'richtext', 'Body');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/edit', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ],
        ]));

        $this->assertSame(303, $response->getStatusCode());

        $rows = $this->connection->fetchAll(
            'SELECT field_key FROM entry_values WHERE entry_id = :id ORDER BY field_key',
            ['id' => $entryId],
        );
        $keys = array_column($rows, 'field_key');
        $this->assertSame(['title'], $keys, 'dropped field rows must be removed.');

        $count = (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM schema_change_log')['c']);
        $this->assertSame(1, $count);
    }

    public function testEditRemovingMiddleFieldPreservesSiblingValues(): void
    {
        $this->signIn();
        $collectionId = $this->seedCollection('posts', 'Posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
            ['key' => 'footer', 'type' => 'text', 'label' => 'Footer'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello');
        $this->seedEntryValue($entryId, 'title', 'text', 'Hello');
        $this->seedEntryValue($entryId, 'body', 'richtext', 'Body');
        $this->seedEntryValue($entryId, 'footer', 'text', 'Bottom');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/edit', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['key' => 'footer', 'type' => 'text', 'label' => 'Footer'],
            ],
        ]));

        $this->assertSame(303, $response->getStatusCode(), 'removing a middle field must not 500.');

        $rows = $this->connection->fetchAll(
            'SELECT field_key, value_text FROM entry_values WHERE entry_id = :id ORDER BY field_key',
            ['id' => $entryId],
        );
        $actual = array_column($rows, 'value_text', 'field_key');
        $this->assertSame(
            ['footer' => 'Bottom', 'title' => 'Hello'],
            $actual,
            'sibling values must keep their own keys; only the removed field is dropped.',
        );

        $count = (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM schema_change_log')['c']);
        $this->assertSame(1, $count);
    }

    public function testEditRollsBackCollectionRowWhenSchemaChangeHelperFails(): void
    {
        $this->signIn();
        $this->seedCollection('posts', 'Posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);

        // Sabotage the schema-change helper's target table so the drop step
        // throws after the collection update has been written.
        $this->connection->execute('DROP TABLE entry_values');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/edit', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts renamed',
            'fields' => [
                ['key' => 'headline', 'type' => 'text', 'label' => 'Headline'],
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());

        $row = $this->connection->fetchOne(
            'SELECT name, schema_definition FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        );
        $this->assertSame('Posts', $row['name'], 'collection update must roll back on helper failure.');
        $schema = json_decode($row['schema_definition'], true);
        $this->assertSame('title', $schema['fields'][0]['key'], 'schema must roll back on helper failure.');
    }

    public function testDeleteRemovesTheCollectionAndRedirectsToList(): void
    {
        $this->signIn();
        $collectionId = $this->seedCollection('posts', 'Posts');
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello');
        $this->seedEntryValue($entryId, 'title', 'text', 'Hello');

        $response = $this->kernel->handle(Request::create('/admin/collections/posts/delete', 'POST'));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/collections', $response->headers->get('Location'));

        $this->assertNull($this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        ));
        $this->assertSame([], $this->connection->fetchAll(
            'SELECT id FROM entries WHERE collection_id = :id',
            ['id' => $collectionId],
        ));
        $this->assertSame([], $this->connection->fetchAll(
            'SELECT id FROM entry_values WHERE entry_id = :id',
            ['id' => $entryId],
        ));
    }

    public function testAddAndRemoveFieldActionsRebuildTheFormWithoutPersisting(): void
    {
        $this->signIn();

        // Add field: form should re-render with two empty field sections and
        // no collection should be created.
        $addResponse = $this->kernel->handle(Request::create('/admin/collections/new', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts',
            'fields' => [
                ['key' => '', 'type' => 'text', 'label' => '', 'required' => '0'],
            ],
            '_add_field' => '1',
        ]));

        $this->assertSame(200, $addResponse->getStatusCode());
        $body = (string) $addResponse->getContent();
        $this->assertStringContainsString('Add field', $body);
        $count = substr_count($body, 'Remove field');
        $this->assertSame(2, $count, 'two field sections should each have a remove button.');

        // Remove field[0]: only the empty trailing field remains.
        $removeResponse = $this->kernel->handle(Request::create('/admin/collections/new', 'POST', [
            'slug' => 'posts',
            'name' => 'Posts',
            'fields' => [
                ['key' => 'first', 'type' => 'text', 'label' => 'First', 'required' => '0'],
                ['key' => '', 'type' => 'text', 'label' => '', 'required' => '0'],
            ],
            '_remove_field[0]' => '1',
        ]));

        $this->assertSame(200, $removeResponse->getStatusCode());
        $body = (string) $removeResponse->getContent();
        $this->assertStringNotContainsString('value="first"', $body);

        $count = (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections')['c']);
        $this->assertSame(0, $count, 'add/remove actions must not persist.');
    }

    public function testUnknownCollectionEditReturns404(): void
    {
        $this->signIn();

        $response = $this->kernel->handle(Request::create('/admin/collections/missing/edit'));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function seedCollection(string $slug, string $name, array $fields = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            [
                'slug' => $slug,
                'name' => $name,
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

    private function seedEntryValue(int $entryId, string $fieldKey, string $fieldType, string $text): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO entry_values
                 (entry_id, field_key, field_type, value,
                  value_text, value_number, value_date, value_bool, value_json,
                  value_index, created_at, updated_at)
             VALUES
                 (:entry_id, :field_key, :field_type, :value,
                  :value_text, NULL, NULL, NULL, NULL,
                  :value_index, :ts, :ts)',
            [
                'entry_id' => $entryId,
                'field_key' => $fieldKey,
                'field_type' => $fieldType,
                'value' => $text,
                'value_text' => $text,
                'value_index' => '',
                'ts' => $now,
            ],
        );
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
