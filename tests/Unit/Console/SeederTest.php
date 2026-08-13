<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Stead\Auth\User;
use Stead\Config\Configuration;
use Stead\Console\Seeder;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Exception\SafeException;

final class SeederTest extends TestCase
{
    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-seed-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->dbPath = $this->projectRoot . '/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'development',
            [
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                ],
            ],
        );

        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
        $this->migrator->migrate();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/database",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
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

    public function testSeedCreatesAdministratorAndSampleCollections(): void
    {
        $report = (new Seeder($this->connection, $this->config))->seed();

        $this->assertSame('admin@example.com', $report['admin_email']);
        $this->assertNotNull($report['admin_password']);
        $this->assertGreaterThanOrEqual(8, strlen((string) $report['admin_password']));
        $this->assertSame(2, $report['collections_created']);

        $user = $this->findUser('admin@example.com');
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->isAdmin());
        $this->assertSame(User::ROLE_ADMIN, $user->roleName);
        $this->assertTrue($user->isActive);

        $this->assertSame(2, $this->collectionCount());
    }

    public function testSeedIsRepeatable(): void
    {
        $seeder = new Seeder($this->connection, $this->config);
        $seeder->seed();
        $second = $seeder->seed();

        $this->assertNull($second['admin_email']);
        $this->assertNull($second['admin_password']);
        $this->assertSame(0, $second['collections_created']);
        $this->assertSame(1, $this->userCount());
        $this->assertSame(2, $this->collectionCount());
    }

    public function testSeedRefusesToRunInProduction(): void
    {
        $config = new Configuration(
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
                ],
            ],
        );

        $this->expectException(SafeException::class);
        (new Seeder($this->connection, $config))->seed();
    }

    public function testSeedReportsGeneratedPasswordOnlyOnFirstRun(): void
    {
        $seeder = new Seeder($this->connection, $this->config);
        $first = $seeder->seed();
        $second = $seeder->seed();

        $this->assertNotNull($first['admin_password']);
        $this->assertNull($second['admin_password']);
    }

    public function testSeedCreatesPostsCollectionWithExpectedFields(): void
    {
        (new Seeder($this->connection, $this->config))->seed();

        $row = $this->connection->fetchOne(
            'SELECT slug, name, schema_definition FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        );
        $this->assertNotNull($row);
        $this->assertSame('Posts', $row['name']);

        $schema = json_decode((string) $row['schema_definition'], true);
        $this->assertIsArray($schema);
        $fields = $schema['fields'];
        $this->assertCount(3, $fields);
        $this->assertSame('title', $fields[0]['key']);
        $this->assertSame('text', $fields[0]['type']);
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('body', $fields[1]['key']);
        $this->assertSame('richtext', $fields[1]['type']);
        $this->assertSame('published_at', $fields[2]['key']);
        $this->assertSame('date', $fields[2]['type']);
    }

    public function testSeedCreatesThreeSampleEntriesWithTypedValues(): void
    {
        (new Seeder($this->connection, $this->config))->seed();

        $rows = $this->connection->fetchAll(
            'SELECT e.id, e.slug, e.title, e.status, e.collection_id
               FROM entries e
               JOIN collections c ON c.id = e.collection_id
               WHERE c.slug = :slug
               ORDER BY e.slug ASC',
            ['slug' => 'posts'],
        );
        $this->assertCount(3, $rows);

        $slugs = array_column($rows, 'slug');
        $this->assertSame(['field-types-in-plain-english', 'hello-world', 'why-a-typed-cms'], $slugs);

        foreach ($rows as $row) {
            $this->assertSame('published', $row['status']);
            $entryId = (int) $row['id'];

            $values = $this->connection->fetchAll(
                'SELECT field_key, field_type, value_text, value_date FROM entry_values
                   WHERE entry_id = :entry_id ORDER BY field_key',
                ['entry_id' => $entryId],
            );

            $byKey = [];
            foreach ($values as $v) {
                $byKey[(string) $v['field_key']] = $v;
            }

            $this->assertArrayHasKey('title', $byKey);
            $this->assertSame('text', $byKey['title']['field_type']);
            $this->assertNotNull($byKey['title']['value_text']);

            $this->assertArrayHasKey('body', $byKey);
            $this->assertSame('richtext', $byKey['body']['field_type']);
            $this->assertNotNull($byKey['body']['value_text']);

            $this->assertArrayHasKey('published_at', $byKey);
            $this->assertSame('date', $byKey['published_at']['field_type']);
            $this->assertNotNull($byKey['published_at']['value_date']);
        }
    }

    public function testSeedIsIdempotentForPostsCollectionAndEntries(): void
    {
        $seeder = new Seeder($this->connection, $this->config);
        $first = $seeder->seed();
        $second = $seeder->seed();
        $third = $seeder->seed();

        $this->assertSame(3, $first['entries_created']);
        $this->assertSame(0, $second['entries_created']);
        $this->assertSame(0, $third['entries_created']);

        $rows = $this->connection->fetchAll(
            'SELECT e.id FROM entries e JOIN collections c ON c.id = e.collection_id WHERE c.slug = :slug',
            ['slug' => 'posts'],
        );
        $this->assertCount(3, $rows, 'entries must not be duplicated on repeat seed runs.');

        $collectionCount = (int) ($this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        )['c'] ?? 0);
        $this->assertSame(1, $collectionCount, 'posts collection must not be duplicated.');
    }

    public function testSeedDefaultCollectionsCreatesBothCollectionsAndIsIdempotent(): void
    {
        $seeder = new Seeder($this->connection, $this->config);

        $first = $seeder->seedDefaultCollections();
        $this->assertSame(2, $first);
        $this->assertSame(2, $this->collectionCount());

        $pages = $this->connection->fetchOne(
            'SELECT slug, name, schema_definition FROM collections WHERE slug = :slug',
            ['slug' => 'pages'],
        );
        $this->assertNotNull($pages);
        $this->assertSame('Pages', $pages['name']);
        $pagesSchema = json_decode((string) $pages['schema_definition'], true);
        $this->assertIsArray($pagesSchema['fields']);
        $this->assertSame('title', $pagesSchema['fields'][0]['key']);
        $this->assertSame('text', $pagesSchema['fields'][0]['type']);
        $this->assertTrue($pagesSchema['fields'][0]['required']);
        $this->assertSame('body', $pagesSchema['fields'][1]['key']);
        $this->assertSame('richtext', $pagesSchema['fields'][1]['type']);

        $posts = $this->connection->fetchOne(
            'SELECT slug FROM collections WHERE slug = :slug',
            ['slug' => 'posts'],
        );
        $this->assertNotNull($posts);

        // Re-running must be a no-op: no collections created, no duplicates.
        $second = $seeder->seedDefaultCollections();
        $this->assertSame(0, $second);
        $this->assertSame(2, $this->collectionCount());

        // Manually re-defining pages under a different shape must not be
        // overwritten — idempotency protects existing rows under the same slug.
        $this->connection->execute(
            'UPDATE collections SET name = :name WHERE slug = :slug',
            ['name' => 'Custom Pages', 'slug' => 'pages'],
        );
        $seeder->seedDefaultCollections();
        $row = $this->connection->fetchOne(
            'SELECT name FROM collections WHERE slug = :slug',
            ['slug' => 'pages'],
        );
        $this->assertSame('Custom Pages', $row['name'], 'existing pages collection must not be overwritten.');
    }

    private function findUser(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT u.id, u.email, u.name, u.password_hash, u.is_active, u.role_id, r.name AS role_name
               FROM users u
               LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.email = :email',
            ['email' => $email],
        );
        return $row === null ? null : User::fromRow($row);
    }

    private function userCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    private function collectionCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections')['c'] ?? 0);
    }
}