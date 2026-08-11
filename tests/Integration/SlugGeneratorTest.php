<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Content\SlugGenerator;

/**
 * Pure unit-level coverage of SlugGenerator. The uniqueness check is
 * database-backed; tests use an in-memory SQLite database through the
 * shared DatabaseTestCase fixture.
 */
final class SlugGeneratorTest extends DatabaseTestCase
{
    protected static function driver(): string
    {
        return 'sqlite';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installFullMigrations();
        $this->migrator->migrate();
    }

    private function installFullMigrations(): void
    {
        $migrationsDir = $this->config->path('paths.migrations');
        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0775, true);
        }
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $file) {
            copy($file, $migrationsDir . '/' . basename($file));
        }
    }

    public function testGenerateLowercasesAndReplacesNonAlphanumerics(): void
    {
        $generator = new SlugGenerator($this->connection);

        $this->assertSame('hello-world', $generator->generate('Hello, World!'));
        $this->assertSame('multi-line', $generator->generate("Multi\nline"));
        $this->assertSame('already-clean', $generator->generate('already-clean'));
        $this->assertSame('trimmed', $generator->generate('   trimmed   '));
    }

    public function testGenerateCollapsesRunsOfNonAlphanumerics(): void
    {
        $generator = new SlugGenerator($this->connection);

        $this->assertSame('a-b-c', $generator->generate('a   b --- c'));
        $this->assertSame('edges', $generator->generate('---edges---'));
    }

    public function testGenerateReturnsEmptyStringWhenSourceHasNoAlphanumerics(): void
    {
        $generator = new SlugGenerator($this->connection);

        $this->assertSame('', $generator->generate('!!! ???'));
    }

    public function testUniqueForCollectionReturnsBaseWhenFree(): void
    {
        $generator = new SlugGenerator($this->connection);

        $this->assertSame('free', $generator->uniqueForCollection('free', 1));
    }

    public function testUniqueForCollectionAppendsSuffixOnCollision(): void
    {
        $generator = new SlugGenerator($this->connection);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:s, :n, :sd, :ts, :ts)',
            ['s' => 'posts', 'n' => 'Posts', 'sd' => '{"fields":[]}', 'ts' => $now],
        );
        $collectionId = (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :s', ['s' => 'posts'],
        )['id'];

        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:c, :s, :t, :st, :ts, :ts)',
            ['c' => $collectionId, 's' => 'taken', 't' => 'taken', 'st' => 'published', 'ts' => $now],
        );

        $this->assertSame('taken-2', $generator->uniqueForCollection('taken', $collectionId));
    }

    public function testUniqueForCollectionSkipsExcludedEntryId(): void
    {
        $generator = new SlugGenerator($this->connection);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:s, :n, :sd, :ts, :ts)',
            ['s' => 'posts', 'n' => 'Posts', 'sd' => '{"fields":[]}', 'ts' => $now],
        );
        $collectionId = (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :s', ['s' => 'posts'],
        )['id'];

        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:c, :s, :t, :st, :ts, :ts)',
            ['c' => $collectionId, 's' => 'mine', 't' => 'mine', 'st' => 'published', 'ts' => $now],
        );
        $row = $this->connection->fetchOne('SELECT id FROM entries WHERE slug = :s', ['s' => 'mine']);
        $existingId = (int) $row['id'];

        $this->assertSame(
            'mine',
            $generator->uniqueForCollection('mine', $collectionId, $existingId),
            'excluded entry must not collide with itself.',
        );
    }

    public function testUniqueForCollectionTreatsOtherCollectionSlugsAsFree(): void
    {
        $generator = new SlugGenerator($this->connection);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:s, :n, :sd, :ts, :ts)',
            ['s' => 'other', 'n' => 'Other', 'sd' => '{"fields":[]}', 'ts' => $now],
        );
        $otherId = (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :s', ['s' => 'other'],
        )['id'];
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:s, :n, :sd, :ts, :ts)',
            ['s' => 'posts', 'n' => 'Posts', 'sd' => '{"fields":[]}', 'ts' => $now],
        );
        $postsId = (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :s', ['s' => 'posts'],
        )['id'];
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:c, :s, :t, :st, :ts, :ts)',
            ['c' => $otherId, 's' => 'taken-elsewhere', 't' => 'x', 'st' => 'published', 'ts' => $now],
        );

        $this->assertSame('taken-elsewhere', $generator->uniqueForCollection('taken-elsewhere', $postsId));
    }
}
