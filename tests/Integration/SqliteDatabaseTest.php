<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use PDO;
use Stead\Database\Connection;
use Stead\Database\Resetter;

final class SqliteDatabaseTest extends DatabaseTestCase
{
    protected static function driver(): string
    {
        return 'sqlite';
    }

    public function testConnectsAndSelectsSqlite(): void
    {
        $row = $this->connection->fetchOne('SELECT sqlite_version() AS v');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('v', $row);
    }

    public function testParameterBinding(): void
    {
        $this->migrator->migrate();
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (:email, :name, :pw, :ts, :ts)',
            ['email' => 'a@b.c', 'name' => 'Ada', 'pw' => 'hash', 'ts' => gmdate('Y-m-d H:i:s')],
        );
        $row = $this->connection->fetchOne(
            'SELECT email, name FROM users WHERE email = :email',
            ['email' => 'a@b.c'],
        );
        $this->assertSame('Ada', $row['name']);
    }

    public function testTransactionRollback(): void
    {
        $this->migrator->migrate();
        try {
            $this->connection->transaction(function (Connection $c) {
                $c->execute(
                    'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (:e, :n, :p, :ts, :ts)',
                    ['e' => 'r@b.c', 'n' => 'Roll', 'p' => 'x', 'ts' => gmdate('Y-m-d H:i:s')],
                );
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $count = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM users WHERE email = :e', ['e' => 'r@b.c']);
        $this->assertSame(0, (int) $count['c']);
    }

    public function testMigrationIdempotence(): void
    {
        $first = $this->migrator->migrate();
        $this->assertCount(1, $first['applied']);
        $this->assertCount(0, $first['skipped']);

        $second = $this->migrator->migrate();
        $this->assertCount(0, $second['applied']);
        $this->assertCount(1, $second['skipped']);
    }

    public function testFailedMigrationDoesNotRecordVersion(): void
    {
        $badDir = $this->config->path('paths.migrations');
        file_put_contents(
            $badDir . '/20990101000001_broken.sqlite.sql',
            "THIS IS NOT VALID SQL;\n",
        );

        try {
            $this->migrator->migrate();
            $this->fail('Expected migration failure.');
        } catch (\Throwable $e) {
            // expected
        }

        $applied = $this->migrator->applied();
        $this->assertNotContains('20990101000001_broken', $applied);
        @unlink($badDir . '/20990101000001_broken.sqlite.sql');
    }

    public function testSchemaRelationshipsPreventOrphans(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['orphan@test', 'O', 'h', $ts, $ts],
        );
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['orphan', 'O', '{}', $ts, $ts],
        );
        $userId = (int) $this->connection->fetchOne('SELECT id FROM users WHERE email = ?', ['orphan@test'])['id'];
        $collId = (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = ?', ['orphan'])['id'];

        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, author_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$collId, 'e1', 'Title', 'draft', $userId, $ts, $ts],
        );
        $entryId = (int) $this->connection->fetchOne('SELECT id FROM entries WHERE slug = ?', ['e1'])['id'];
        $this->connection->execute(
            'INSERT INTO entry_values (entry_id, field_key, field_type, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$entryId, 'body', 'text', 'hi', $ts, $ts],
        );

        $this->connection->pdo()->exec('PRAGMA foreign_keys = ON');
        $this->connection->execute('DELETE FROM entries WHERE id = ?', [$entryId]);
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM entry_values WHERE entry_id = ?', [$entryId]);
        $this->assertSame(0, (int) $row['c'], 'entry_values should be cascaded on entry delete.');
    }

    public function testUniqueEmailConstraint(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['dup@test', 'D', 'h', $ts, $ts],
        );
        $this->expectException(\Throwable::class);
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['dup@test', 'D2', 'h2', $ts, $ts],
        );
    }

    public function testUniqueCollectionSlug(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['posts', 'Posts', '{}', $ts, $ts],
        );
        $this->expectException(\Throwable::class);
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['posts', 'Other', '{}', $ts, $ts],
        );
    }

    public function testCollectionSchemaExtensionPoint(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');
        $schema = json_encode([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'required' => true],
                ['key' => 'body', 'type' => 'markdown'],
            ],
        ]);
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['articles', 'Articles', $schema, $ts, $ts],
        );
        $row = $this->connection->fetchOne('SELECT schema_definition FROM collections WHERE slug = ?', ['articles']);
        $this->assertSame($schema, $row['schema_definition']);
    }

    public function testFreshResetClearsTables(): void
    {
        $this->migrator->migrate();
        $ts = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['fresh@test', 'F', 'h', $ts, $ts],
        );
        $resetter = new Resetter($this->connection, $this->config);
        $resetter->reset();
        $this->migrator->migrate();
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM users');
        $this->assertSame(0, (int) $row['c']);
    }

    public function testDriverQuoting(): void
    {
        $this->assertStringStartsWith('"', $this->connection->quote('users'));
        $this->assertStringEndsWith('"', $this->connection->quote('users'));
    }
}
