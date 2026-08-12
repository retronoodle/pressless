<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\Entry;
use Stead\Content\EntryRepository;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\FieldType\TextFieldType;
use Stead\Content\SlugGenerator;
use Stead\Media\MediaRepository;

/**
 * Round-trips entry SEO fields (meta_title, meta_description, og_image_id)
 * through EntryRepository::save() against a real SQLite schema, including
 * the FK link to media.
 */
final class EntrySeoSaveTest extends DatabaseTestCase
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
        return new FieldTypeRegistry([new TextFieldType()]);
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
            ['fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true]]],
        ));
    }

    private function seedMedia(): int
    {
        $media = new MediaRepository($this->connection);
        $row = $media->create([
            'filename' => 'og.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1,
            'path' => '1/og.png',
            'uploaded_by' => null,
        ]);
        return $row->id();
    }

    public function testSavePersistsSeoFieldsAndReadBackReturnsThem(): void
    {
        $collection = $this->postsCollection();
        $mediaId = $this->seedMedia();
        $repo = $this->buildRepository();

        $saved = $repo->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'Hello'],
            null,
            [
                'meta_title' => 'Hello — custom meta title',
                'meta_description' => 'A friendly description for SEO and shares.',
                'og_image_id' => $mediaId,
            ],
        );

        $reloaded = $repo->find($saved->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('Hello — custom meta title', $reloaded->metaTitle());
        $this->assertSame('A friendly description for SEO and shares.', $reloaded->metaDescription());
        $this->assertSame($mediaId, $reloaded->ogImageId());
    }

    public function testSaveWithoutSeoDefaultsAllFieldsToNull(): void
    {
        $collection = $this->postsCollection();
        $repo = $this->buildRepository();

        $saved = $repo->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'No SEO'],
        );

        $reloaded = $repo->find($saved->id());
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->metaTitle());
        $this->assertNull($reloaded->metaDescription());
        $this->assertNull($reloaded->ogImageId());
    }

    public function testResaveUpdatesTheSeoColumns(): void
    {
        $collection = $this->postsCollection();
        $mediaId = $this->seedMedia();
        $repo = $this->buildRepository();

        $saved = $repo->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'First'],
            null,
            ['meta_title' => 'Old title', 'meta_description' => 'Old description', 'og_image_id' => $mediaId],
        );

        $resaved = $repo->save(
            $saved,
            $collection,
            ['title' => 'First'],
            null,
            ['meta_title' => 'New title', 'meta_description' => 'New description', 'og_image_id' => null],
        );

        $reloaded = $repo->find($resaved->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('New title', $reloaded->metaTitle());
        $this->assertSame('New description', $reloaded->metaDescription());
        $this->assertNull($reloaded->ogImageId());
    }
}