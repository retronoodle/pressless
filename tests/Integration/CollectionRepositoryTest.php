<?php

declare(strict_types=1);

namespace Pressless\Tests\Integration;

use Pressless\Content\Collection;
use Pressless\Content\CollectionRepository;
use Pressless\Exception\SafeException;

/**
 * Round-trips Collection value objects through CollectionRepository against
 * a real SQLite schema. The tests are sqlite-only because the column
 * representation of `schema_definition` differs by driver (TEXT vs JSON) and
 * the SQLite implementation is what Pressless uses in CI without MySQL.
 */
final class CollectionRepositoryTest extends DatabaseTestCase
{
    protected static function driver(): string
    {
        return 'sqlite';
    }

    private function makeRepository(): CollectionRepository
    {
        $this->migrator->migrate();
        return new CollectionRepository($this->connection);
    }

    public function testCreatePersistsTheCollectionAndRoundTripsTheSchema(): void
    {
        $repo = $this->makeRepository();

        $collection = new Collection(
            0,
            'posts',
            'Posts',
            ['fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
            ]],
        );

        $created = $repo->save($collection);

        $this->assertSame(0, $collection->id(), 'original value object stays unchanged.');
        $this->assertGreaterThan(0, $created->id());
        $this->assertSame('posts', $created->slug());
        $this->assertSame('Posts', $created->name());

        $reloaded = $repo->find($created->id());
        $this->assertNotNull($reloaded);
        $this->assertSame($created->id(), $reloaded->id());
        $this->assertSame('posts', $reloaded->slug());
        $this->assertCount(2, $reloaded->fields());
        $this->assertSame('title', $reloaded->fields()[0]['key']);
        $this->assertSame('richtext', $reloaded->fields()[1]['type']);

        $bySlug = $repo->findBySlug('posts');
        $this->assertNotNull($bySlug);
        $this->assertSame($reloaded->id(), $bySlug->id());
    }

    public function testUpdateReplacesTheSchema(): void
    {
        $repo = $this->makeRepository();

        $created = $repo->save(new Collection(0, 'pages', 'Pages', [
            'fields' => [['key' => 'title', 'type' => 'text']],
        ]));

        $updated = $repo->save($created->withSchema([
            'fields' => [
                ['key' => 'title', 'type' => 'text'],
                ['key' => 'body', 'type' => 'richtext'],
            ],
        ])->withName('Public pages'));

        $this->assertSame($created->id(), $updated->id());
        $reloaded = $repo->find($updated->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('Public pages', $reloaded->name());
        $this->assertCount(2, $reloaded->fields());
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $repo = $this->makeRepository();
        $repo->save(new Collection(0, 'pages', 'Pages', ['fields' => []]));

        $this->expectException(SafeException::class);
        $repo->save(new Collection(0, 'pages', 'Other pages', ['fields' => []]));
    }

    public function testExistsBySlugSupportsExclusion(): void
    {
        $repo = $this->makeRepository();
        $created = $repo->save(new Collection(0, 'pages', 'Pages', ['fields' => []]));

        $this->assertTrue($repo->existsBySlug('pages'));
        $this->assertFalse($repo->existsBySlug('pages', $created->id()));
        $this->assertFalse($repo->existsBySlug('nope'));
    }

    public function testAllListsCollectionsInSlugOrder(): void
    {
        $repo = $this->makeRepository();
        $repo->save(new Collection(0, 'zeta', 'Zeta', ['fields' => []]));
        $repo->save(new Collection(0, 'alpha', 'Alpha', ['fields' => []]));
        $repo->save(new Collection(0, 'mid', 'Mid', ['fields' => []]));

        $slugs = array_map(static fn(Collection $c) => $c->slug(), $repo->all());

        $this->assertSame(['alpha', 'mid', 'zeta'], $slugs);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $repo = $this->makeRepository();
        $created = $repo->save(new Collection(0, 'pages', 'Pages', ['fields' => []]));

        $repo->delete($created->id());
        $this->assertNull($repo->find($created->id()));
        $this->assertFalse($repo->existsBySlug('pages'));
    }

    public function testMalformedJsonInTheDatabaseIsRecoveredAsEmptySchema(): void
    {
        $this->migrator->migrate();
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            ['slug' => 'broken', 'name' => 'Broken', 'schema' => '{not-json', 'ts' => $now],
        );

        $repo = new CollectionRepository($this->connection);
        $loaded = $repo->findBySlug('broken');

        $this->assertNotNull($loaded);
        $this->assertSame(['fields' => []], $loaded->schema());
    }
}
