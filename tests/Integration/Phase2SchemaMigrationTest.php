<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Console\ServePreflight;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Database\Resetter;

/**
 * Verifies the Phase 2 schema migrations:
 *  - `schema_change_log` is created with the unique
 *    `(collection_id, new_field_set_hash)` constraint
 *  - `entry_values` gains the typed-uniform columns
 *    (`value_text`, `value_number`, `value_date`, `value_bool`, `value_json`)
 *    plus a unique key on `(entry_id, field_key)`
 *  - both migrations apply once and skip on subsequent runs
 *  - the reset path drops the new tables cleanly
 *
 * SQLite-only — MySQL is exercised in CI when DB_HOST is set.
 */
final class Phase2SchemaMigrationTest extends TestCase
{
    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-phase2-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
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
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
            ],
        );

        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        foreach ([
            $this->projectRoot . '/database/migrations',
            $this->projectRoot . '/database',
            $this->projectRoot . '/var/log',
            $this->projectRoot . '/var',
            $this->projectRoot,
        ] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @unlink($dir . '/' . $entry);
            }
            @rmdir($dir);
        }
        @unlink($this->dbPath);
    }

    public function testBothPhase2MigrationsApply(): void
    {
        $result = $this->migrator->migrate();

        $applied = $result['applied'];
        $this->assertContains('20260811000002_schema_change_log', $applied);
        $this->assertContains('20260811000003_entry_values_typed_columns', $applied);

        $row = $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_change_log'",
        );
        $this->assertNotNull($row, 'schema_change_log table should exist after migration.');
    }

    public function testMigrationsAreIdempotent(): void
    {
        $first = $this->migrator->migrate();
        $this->assertContains('20260811000002_schema_change_log', $first['applied']);
        $this->assertContains('20260811000003_entry_values_typed_columns', $first['applied']);

        $second = $this->migrator->migrate();
        $this->assertNotContains('20260811000002_schema_change_log', $second['applied']);
        $this->assertNotContains('20260811000003_entry_values_typed_columns', $second['applied']);
        $this->assertContains('20260811000002_schema_change_log', $second['skipped']);
        $this->assertContains('20260811000003_entry_values_typed_columns', $second['skipped']);
    }

    public function testEntryValuesTypedColumnsAreAdded(): void
    {
        $this->migrator->migrate();

        $columns = $this->tableColumns('entry_values');
        foreach (['value_text', 'value_number', 'value_date', 'value_bool', 'value_json', 'field_key'] as $column) {
            $this->assertArrayHasKey($column, $columns, "entry_values.{$column} column should exist.");
        }

        foreach (['value_text', 'value_number', 'value_date', 'value_bool', 'value_json'] as $column) {
            $this->assertSame(0, (int) $columns[$column]['notnull'], "{$column} must be nullable.");
        }
    }

    public function testEntryValuesUniqueKeyOnEntryAndField(): void
    {
        $this->migrator->migrate();

        $ts = gmdate('Y-m-d H:i:s');
        $this->seedCollectionAndEntry('coll-uniq', 'entry-uniq', $ts);

        $this->connection->execute(
            'INSERT INTO entry_values (entry_id, field_key, field_type, value, value_text, created_at, updated_at)
             VALUES (:entry_id, :field_key, :field_type, :value, :value_text, :ts, :ts)',
            [
                'entry_id' => 1,
                'field_key' => 'title',
                'field_type' => 'text',
                'value' => 'first',
                'value_text' => 'first',
                'ts' => $ts,
            ],
        );

        $this->expectException(\Throwable::class);
        $this->connection->execute(
            'INSERT INTO entry_values (entry_id, field_key, field_type, value, value_text, created_at, updated_at)
             VALUES (:entry_id, :field_key, :field_type, :value, :value_text, :ts, :ts)',
            [
                'entry_id' => 1,
                'field_key' => 'title',
                'field_type' => 'text',
                'value' => 'second',
                'value_text' => 'second',
                'ts' => $ts,
            ],
        );
    }

    public function testSchemaChangeLogEnforcesUniqueCollectionHash(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');

        $this->seedCollectionAndEntry('coll-log', 'entry-log', $ts);

        $this->connection->execute(
            'INSERT INTO schema_change_log (collection_id, previous_field_set_hash, new_field_set_hash, applied_at)
             VALUES (:collection_id, :previous_field_set_hash, :new_field_set_hash, :ts)',
            [
                'collection_id' => 1,
                'previous_field_set_hash' => null,
                'new_field_set_hash' => str_repeat('a', 40),
                'ts' => $ts,
            ],
        );

        $this->expectException(\Throwable::class);
        $this->connection->execute(
            'INSERT INTO schema_change_log (collection_id, previous_field_set_hash, new_field_set_hash, applied_at)
             VALUES (:collection_id, :previous_field_set_hash, :new_field_set_hash, :ts)',
            [
                'collection_id' => 1,
                'previous_field_set_hash' => str_repeat('b', 40),
                'new_field_set_hash' => str_repeat('a', 40),
                'ts' => $ts,
            ],
        );
    }

    public function testSchemaChangeLogAllowsSameHashForDifferentCollections(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');

        $this->seedCollection('alpha', $ts);
        $this->seedCollection('beta', $ts);

        $hash = str_repeat('c', 40);
        $this->connection->execute(
            'INSERT INTO schema_change_log (collection_id, previous_field_set_hash, new_field_set_hash, applied_at)
             VALUES (:collection_id, NULL, :hash, :ts)',
            ['collection_id' => 1, 'hash' => $hash, 'ts' => $ts],
        );
        $this->connection->execute(
            'INSERT INTO schema_change_log (collection_id, previous_field_set_hash, new_field_set_hash, applied_at)
             VALUES (:collection_id, NULL, :hash, :ts)',
            ['collection_id' => 2, 'hash' => $hash, 'ts' => $ts],
        );

        $count = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM schema_change_log');
        $this->assertSame(2, (int) $count['c']);
    }

    public function testResetterDropsSchemaChangeLogDependencySafely(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');
        $this->seedCollectionAndEntry('coll-reset', 'entry-reset', $ts);
        $this->connection->execute(
            'INSERT INTO schema_change_log (collection_id, previous_field_set_hash, new_field_set_hash, applied_at)
             VALUES (1, NULL, :hash, :ts)',
            ['hash' => str_repeat('d', 40), 'ts' => $ts],
        );

        $row = $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_change_log'",
        );
        $this->assertNotNull($row, 'precondition: schema_change_log should exist before reset.');

        $resetter = new Resetter($this->connection, $this->config);
        $resetter->reset();

        $row = $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_change_log'",
        );
        $this->assertNull($row, 'schema_change_log should be dropped by the resetter.');

        $this->migrator->migrate();
        $row = $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_change_log'",
        );
        $this->assertNotNull($row, 'schema_change_log should be recreated by a fresh migration.');
    }

    public function testServePreflightFreshSeedReplaysMigrations(): void
    {
        $preflight = new ServePreflight($this->connection, $this->config);
        $preflight->run(
            fresh: true,
            seed: false,
            server: ['host' => '127.0.0.1', 'port' => 8000],
        );

        $row = $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_change_log'",
        );
        $this->assertNotNull($row, 'fresh+seed preflight must end with schema_change_log applied.');
    }

    /**
     * @return array<string, array{name: string, type: string, notnull: int, pk: int}>
     */
    private function tableColumns(string $table): array
    {
        $rows = $this->connection->fetchAll(\sprintf('PRAGMA table_info(%s)', $table));
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['name']] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'notnull' => (int) $row['notnull'],
                'pk' => (int) $row['pk'],
            ];
        }
        return $columns;
    }

    private function seedCollection(string $slug, string $ts): void
    {
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema_definition, :created_at, :updated_at)',
            [
                'slug' => $slug,
                'name' => ucfirst($slug),
                'schema_definition' => '{}',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
        );
    }

    private function seedCollectionAndEntry(string $collSlug, string $entrySlug, string $ts): void
    {
        $this->seedCollection($collSlug, $ts);
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:collection_id, :slug, :title, :status, :created_at, :updated_at)',
            [
                'collection_id' => 1,
                'slug' => $entrySlug,
                'title' => ucfirst($entrySlug),
                'status' => 'draft',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
        );
    }
}
