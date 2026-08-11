<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\Entry;
use Stead\Content\EntryRepository;
use Stead\Content\FieldType\BooleanFieldType;
use Stead\Content\FieldType\DateFieldType;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\FieldType\NumberFieldType;
use Stead\Content\FieldType\RichtextFieldType;
use Stead\Content\FieldType\TextFieldType;
use Stead\Content\SlugGenerator;

/**
 * Round-trips Entry value objects through EntryRepository against a real
 * SQLite schema. Covers save / resave / list / delete plus slug generation,
 * collision handling, and slug preservation when the source field is
 * unchanged.
 */
final class EntryRepositoryTest extends DatabaseTestCase
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

    private function buildRegistry(): FieldTypeRegistry
    {
        return new FieldTypeRegistry([
            new TextFieldType(),
            new RichtextFieldType(),
            new NumberFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
        ]);
    }

    private function buildRepository(): EntryRepository
    {
        return new EntryRepository(
            $this->connection,
            $this->buildRegistry(),
            new SlugGenerator($this->connection),
        );
    }

    private function postsCollection(): Collection
    {
        $repo = new CollectionRepository($this->connection);
        return $repo->save(new Collection(
            0,
            'posts',
            'Posts',
            ['fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
                ['key' => 'rating', 'type' => 'number', 'label' => 'Rating'],
                ['key' => 'published_at', 'type' => 'date', 'label' => 'Published at'],
            ]],
        ));
    }

    public function testSavePersistsEntryAndWritesOneTypedValueRowPerField(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();

        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Hello, world',
            'body' => 'First post body.',
            'rating' => 4.5,
            'published_at' => '2025-02-01',
        ]);

        $this->assertGreaterThan(0, $saved->id());
        $this->assertSame('hello-world', $saved->slug());
        $this->assertSame('Hello, world', $saved->value('title'));
        $this->assertSame('First post body.', $saved->value('body'));
        $this->assertSame(4.5, $saved->value('rating'));
        $this->assertSame('2025-02-01', $saved->value('published_at'));

        $valueRows = $this->connection->fetchAll(
            'SELECT field_key FROM entry_values WHERE entry_id = :entry_id ORDER BY field_key ASC',
            ['entry_id' => $saved->id()],
        );
        $this->assertSame(['body', 'published_at', 'rating', 'title'], array_column($valueRows, 'field_key'));
    }

    public function testFindByCollectionAndSlugReturnsTheEntry(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Second Post',
            'body' => 'Body text.',
        ]);

        $found = $repo->findByCollectionAndSlug($collection->id(), $saved->slug());

        $this->assertNotNull($found);
        $this->assertSame($saved->id(), $found->id());
        $this->assertSame('Second Post', $found->value('title'));
    }

    public function testResaveReplacesTheTypedValueRows(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Original',
            'body' => 'Original body.',
            'rating' => 4.5,
            'published_at' => '2025-02-01',
        ]);

        $resaved = $repo->save($saved, $collection, [
            'title' => 'Updated',
            'body' => 'Updated body.',
            'rating' => 4.5,
            'published_at' => '2025-02-01',
        ]);

        $this->assertSame($saved->id(), $resaved->id());
        $this->assertSame('Updated', $resaved->value('title'));
        $this->assertSame('Updated body.', $resaved->value('body'));

        $valueRows = $this->connection->fetchAll(
            'SELECT field_key FROM entry_values WHERE entry_id = :entry_id ORDER BY field_key ASC',
            ['entry_id' => $resaved->id()],
        );
        $this->assertSame(['body', 'published_at', 'rating', 'title'], array_column($valueRows, 'field_key'));
        $this->assertCount(4, $valueRows, 'old value rows for the entry must be replaced, not duplicated.');
    }

    public function testIdempotentResaveWritesNoDuplicateValueRows(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Stable',
            'body' => 'Body',
            'rating' => 1,
            'published_at' => '2025-01-01',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $repo->save($saved, $collection, [
                'title' => 'Stable',
                'body' => 'Body',
                'rating' => 1,
                'published_at' => '2025-01-01',
            ]);
        }

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM entry_values WHERE entry_id = :entry_id',
            ['entry_id' => $saved->id()],
        )['c'];
        $this->assertSame(4, $count, 'resave must replace value rows in place, not duplicate them.');
    }

    public function testSlugCollisionAppendsNumericSuffix(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $first = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Same Title',
            'body' => 'First.',
        ]);
        $second = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Same Title',
            'body' => 'Second.',
        ]);

        $this->assertSame('same-title', $first->slug());
        $this->assertSame('same-title-2', $second->slug());
    }

    public function testEditingUnrelatedFieldsPreservesTheSlug(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Stable Title',
            'body' => 'Body one.',
        ]);

        $resaved = $repo->save($saved, $collection, [
            'title' => 'Stable Title',
            'body' => 'Body two.',
        ]);

        $this->assertSame($saved->slug(), $resaved->slug());
    }

    public function testEditingTheSlugSourceRegeneratesTheSlug(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Original',
            'body' => 'Body.',
        ]);
        $originalSlug = $saved->slug();

        $renamed = $repo->save($saved, $collection, [
            'title' => 'Renamed',
            'body' => 'Body.',
        ]);

        $this->assertNotSame($originalSlug, $renamed->slug());
        $this->assertSame('renamed', $renamed->slug());
    }

    public function testListByCollectionReturnsAllEntriesForThatCollection(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $otherCollection = (new CollectionRepository($this->connection))->save(new Collection(
            0,
            'pages',
            'Pages',
            ['fields' => [['key' => 'title', 'type' => 'text']]],
        ));

        $a = $repo->save(new Entry(0, $collection->id(), '', []), $collection, ['title' => 'A', 'body' => '']);
        $b = $repo->save(new Entry(0, $collection->id(), '', []), $collection, ['title' => 'B', 'body' => '']);
        $repo->save(new Entry(0, $otherCollection->id(), '', []), $otherCollection, ['title' => 'C']);

        $listed = $repo->listByCollection($collection->id());

        $this->assertCount(2, $listed);
        $this->assertSame([$a->id(), $b->id()], array_map(static fn(Entry $e) => $e->id(), $listed));
    }

    public function testListByCollectionPagedReturnsFirstPageAndIndicatesMoreEntriesExist(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = [];
        for ($i = 0; $i < EntryRepository::PAGE_SIZE + 5; $i++) {
            $saved[] = $repo->save(
                new Entry(0, $collection->id(), '', []),
                $collection,
                ['title' => 'Post ' . $i, 'body' => ''],
            );
        }

        $page = $repo->listByCollectionPaged($collection->id(), 1);

        $this->assertCount(EntryRepository::PAGE_SIZE, $page['entries']);
        $this->assertTrue($page['has_next']);
        $this->assertSame(EntryRepository::PAGE_SIZE + 5, $page['total']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(EntryRepository::PAGE_SIZE, $page['page_size']);
        $this->assertSame(
            array_slice(array_map(static fn(Entry $e) => $e->id(), $saved), 0, EntryRepository::PAGE_SIZE),
            array_map(static fn(Entry $e) => $e->id(), $page['entries']),
        );
    }

    public function testListByCollectionPagedReturnsEmptyPageBeyondTheLastEntry(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $repo->save(new Entry(0, $collection->id(), '', []), $collection, ['title' => 'Only', 'body' => '']);

        $page = $repo->listByCollectionPaged($collection->id(), 99);

        $this->assertSame([], $page['entries']);
        $this->assertFalse($page['has_next']);
        $this->assertSame(1, $page['total']);
        $this->assertSame(99, $page['page']);
    }

    public function testListByCollectionPagedReturnsAllEntriesAndIndicatesNoNextPageWhenFewerThanPageSize(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = [];
        for ($i = 0; $i < 3; $i++) {
            $saved[] = $repo->save(
                new Entry(0, $collection->id(), '', []),
                $collection,
                ['title' => 'Few ' . $i, 'body' => ''],
            );
        }

        $page = $repo->listByCollectionPaged($collection->id(), 1);

        $this->assertCount(3, $page['entries']);
        $this->assertFalse($page['has_next']);
        $this->assertSame(3, $page['total']);
        $this->assertSame(
            array_map(static fn(Entry $e) => $e->id(), $saved),
            array_map(static fn(Entry $e) => $e->id(), $page['entries']),
        );
    }

    public function testDeleteRemovesEntryAndValues(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();
        $saved = $repo->save(new Entry(0, $collection->id(), '', []), $collection, [
            'title' => 'Doomed',
            'body' => 'Body.',
        ]);

        $repo->delete($saved->id());

        $this->assertNull($repo->find($saved->id()));
        $valueRows = $this->connection->fetchAll(
            'SELECT id FROM entry_values WHERE entry_id = :entry_id',
            ['entry_id' => $saved->id()],
        );
        $this->assertSame([], $valueRows);
    }
}
