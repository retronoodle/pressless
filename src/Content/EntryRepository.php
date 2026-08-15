<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Config\Configuration;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Reads and writes `entries` rows plus their `entry_values` rows.
 *
 * The repository is the only place entry persistence touches SQL. The
 * collection's schema and the registered field types own validation and
 * binding; callers are expected to validate the payload before calling
 * {@see save()}.
 */
final class EntryRepository
{
    public const PAGE_SIZE = 10;
    public const STATUS_DRAFT = Entry::STATUS_DRAFT;
    public const STATUS_PUBLISHED = Entry::STATUS_PUBLISHED;

    public function __construct(
        private readonly Connection $connection,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly SlugGenerator $slugs,
        private readonly ?RevisionRepository $revisions = null,
        private readonly ?Configuration $config = null,
        private readonly ?RedirectRepository $redirects = null,
    ) {
    }

    public function find(int $id): ?Entry
    {
        $row = $this->connection->fetchOne(
            'SELECT id, collection_id, slug, title, status, published_at, author_id, meta_title, meta_description, og_image_id FROM entries WHERE id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function findByCollectionAndSlug(int $collectionId, string $slug, ?string $status = null): ?Entry
    {
        $sql = 'SELECT id, collection_id, slug, title, status, published_at, author_id, meta_title, meta_description, og_image_id FROM entries
              WHERE collection_id = :collection_id AND slug = :slug';
        $params = ['collection_id' => $collectionId, 'slug' => $slug];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $row = $this->connection->fetchOne($sql, $params);
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @return list<Entry>
     */
    public function listByCollection(int $collectionId, ?string $status = null): array
    {
        $sql = 'SELECT id, collection_id, slug, title, status, published_at, author_id, meta_title, meta_description, og_image_id FROM entries
              WHERE collection_id = :collection_id ORDER BY id ASC';
        $params = ['collection_id' => $collectionId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $rows = $this->connection->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /**
     * Returns one page of entries for the collection ordered by id ascending.
     * Page numbers are 1-based; values below 1 are treated as 1. A page
     * beyond the last entry returns an empty entries list without raising.
     *
     * @return array{entries: list<Entry>, has_next: bool, total: int, page: int, page_size: int}
     */
    public function listByCollectionPaged(int $collectionId, int $page, ?string $status = null): array
    {
        $pageSize = self::PAGE_SIZE;
        $page = $page < 1 ? 1 : $page;
        $offset = ($page - 1) * $pageSize;

        $total = $this->countByCollection($collectionId, $status);

        $sql = 'SELECT id, collection_id, slug, title, status, published_at, author_id, meta_title, meta_description, og_image_id FROM entries
              WHERE collection_id = :collection_id';
        $params = ['collection_id' => $collectionId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit OFFSET :offset';
        $params['limit'] = $pageSize;
        $params['offset'] = $offset;

        $rows = $this->connection->fetchAll($sql, $params);
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $this->hydrate($row);
        }

        return [
            'entries' => $entries,
            'has_next' => ($offset + count($entries)) < $total,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * Same shape as {@see listByCollectionPaged()}, but ordered most-recent
     * first (`published_at DESC, id DESC`). Used for blog-style feeds where
     * the existing id-ascending order would be insertion order rather than
     * recency. The collection listing route keeps id-ascending behaviour.
     *
     * Entries with `published_at IS NULL` sort last in SQLite (NULLs sort
     * before non-null on ASC, so they sort last on DESC). The `id DESC`
     * tiebreaker keeps the order stable when two entries share the same
     * published_at timestamp.
     *
     * @return array{entries: list<Entry>, has_next: bool, total: int, page: int, page_size: int}
     */
    public function listByCollectionPagedRecent(int $collectionId, int $page, ?string $status = null): array
    {
        $pageSize = self::PAGE_SIZE;
        $page = $page < 1 ? 1 : $page;
        $offset = ($page - 1) * $pageSize;

        $total = $this->countByCollection($collectionId, $status);

        $sql = 'SELECT id, collection_id, slug, title, status, published_at, author_id, meta_title, meta_description, og_image_id FROM entries
              WHERE collection_id = :collection_id';
        $params = ['collection_id' => $collectionId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = $pageSize;
        $params['offset'] = $offset;

        $rows = $this->connection->fetchAll($sql, $params);
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $this->hydrate($row);
        }

        return [
            'entries' => $entries,
            'has_next' => ($offset + count($entries)) < $total,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function count(): int
    {
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM entries');
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Lists all published entries, joined with their collection slug, for
     * admin pickers (e.g. the homepage-page picker). Sorted by collection
     * slug then title so the dropdown groups sensibly.
     *
     * @return list<array{id: int, title: string, slug: string, collection_slug: string, collection_name: string}>
     */
    public function listPublishedForPicker(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT e.id, e.title, e.slug, c.slug AS collection_slug, c.name AS collection_name
               FROM entries e
               JOIN collections c ON c.id = e.collection_id
              WHERE e.status = :status
              ORDER BY c.slug ASC, e.title ASC, e.id ASC',
            ['status' => self::STATUS_PUBLISHED],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'collection_slug' => (string) $row['collection_slug'],
                'collection_name' => (string) $row['collection_name'],
            ];
        }
        return $out;
    }

    public function countByCollection(int $collectionId, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM entries WHERE collection_id = :collection_id';
        $params = ['collection_id' => $collectionId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $row = $this->connection->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Persists the entry and all of its typed values inside a single
     * transaction. The slug source field is detected from the collection's
     * schema (first text-like field if none is marked) and the slug is
     * regenerated only when that source field changes; unrelated edits
     * preserve the existing slug.
     *
     * New entries default to `status = draft` (matches the column's own
     * default). Editing an existing entry never changes its status —
     * publish/unpublish are explicit calls on this repository. A revision
     * row is written before the existing entry_values are replaced,
     * capturing the entry's pre-save state; the oldest revisions beyond
     * the configured retention limit are pruned in the same transaction.
     *
     * The optional `$seo` array carries the entry-level SEO fields
     * (`meta_title`, `meta_description`, `og_image_id`) which live on the
     * `entries` row rather than in `entry_values`. Pass an empty array to
     * leave them as-is for updates or default NULL for inserts.
     *
     * @param array<string, mixed> $payload map of field_key => submitted value
     * @param array{meta_title?: ?string, meta_description?: ?string, og_image_id?: ?int} $seo
     */
    public function save(Entry $entry, Collection $collection, array $payload, ?int $authorId = null, array $seo = []): Entry
    {
        $fields = $collection->fields();
        $now = self::now();
        $slugSourceKey = $this->resolveSlugSourceKey($fields);
        $slugSourceRaw = $payload[$slugSourceKey] ?? null;
        $slugSourceString = $this->stringify($slugSourceRaw);

        $seoMetaTitle = isset($seo['meta_title']) && $seo['meta_title'] !== ''
            ? (string) $seo['meta_title']
            : null;
        $seoMetaDescription = isset($seo['meta_description']) && $seo['meta_description'] !== ''
            ? (string) $seo['meta_description']
            : null;
        $seoOgImageId = isset($seo['og_image_id']) && $seo['og_image_id'] !== null && $seo['og_image_id'] !== '' && (int) $seo['og_image_id'] > 0
            ? (int) $seo['og_image_id']
            : null;

        $resultEntry = $this->connection->transaction(function () use (
            $entry,
            $collection,
            $payload,
            $fields,
            $slugSourceKey,
            $slugSourceString,
            $now,
            $authorId,
            $seoMetaTitle,
            $seoMetaDescription,
            $seoOgImageId,
        ): array {
            if ($entry->id() === 0) {
                $slug = $this->slugs->uniqueForCollection(
                    $this->slugs->generate($slugSourceString),
                    $collection->id(),
                );
                $title = $slugSourceString !== '' ? $slugSourceString : $slug;

                $this->connection->execute(
                    'INSERT INTO entries (collection_id, slug, title, author_id, meta_title, meta_description, og_image_id, created_at, updated_at)
                     VALUES (:collection_id, :slug, :title, :author_id, :meta_title, :meta_description, :og_image_id, :created_at, :updated_at)',
                    [
                        'collection_id' => $collection->id(),
                        'slug' => $slug,
                        'title' => $title,
                        'author_id' => $authorId,
                        'meta_title' => $seoMetaTitle,
                        'meta_description' => $seoMetaDescription,
                        'og_image_id' => $seoOgImageId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
                $row = $this->connection->fetchOne(
                    'SELECT id FROM entries WHERE collection_id = :collection_id AND slug = :slug',
                    ['collection_id' => $collection->id(), 'slug' => $slug],
                );
                if ($row === null) {
                    throw new SafeException('Entry could not be read back after creation.');
                }
                $entryId = (int) $row['id'];
            } else {
                $existing = $this->find($entry->id());
                if ($existing === null) {
                    throw new SafeException('Entry not found.');
                }
                $entryId = $entry->id();

                if ($this->revisions !== null) {
                    $this->revisions->save($entryId, $this->snapshotForRevision($existing, $slugSourceKey), $authorId);
                }

                $existingSource = $existing->value($slugSourceKey);
                $sourceChanged = $this->stringify($existingSource) !== $slugSourceString;
                $oldSlug = $existing->slug();

                if ($sourceChanged) {
                    $slug = $this->slugs->uniqueForCollection(
                        $this->slugs->generate($slugSourceString),
                        $collection->id(),
                        $entryId,
                    );
                } else {
                    $slug = $oldSlug;
                }
                $title = $slugSourceString !== '' ? $slugSourceString : $slug;

                $this->connection->execute(
                    'UPDATE entries
                        SET slug = :slug,
                            title = :title,
                            meta_title = :meta_title,
                            meta_description = :meta_description,
                            og_image_id = :og_image_id,
                            updated_at = :updated_at
                      WHERE id = :id',
                    [
                        'slug' => $slug,
                        'title' => $title,
                        'meta_title' => $seoMetaTitle,
                        'meta_description' => $seoMetaDescription,
                        'og_image_id' => $seoOgImageId,
                        'updated_at' => $now,
                        'id' => $entryId,
                    ],
                );

                $this->connection->execute(
                    'DELETE FROM entry_values WHERE entry_id = :entry_id',
                    ['entry_id' => $entryId],
                );

                if ($sourceChanged && $slug !== $oldSlug && $this->redirects !== null) {
                    $this->redirects->upsert(
                        $this->publicPath($collection->slug(), $oldSlug),
                        $this->publicPath($collection->slug(), $slug),
                    );
                }
            }

            $this->writeValues($entryId, $collection, $payload, $fields, $now);

            if ($this->revisions !== null) {
                $this->revisions->pruneOldest($entryId, $this->retentionLimit());
            }

            return [
                'id' => $entryId,
                'slug' => $slug,
                'collection_id' => $collection->id(),
            ];
        });

        $reloaded = $this->find($resultEntry['id']);
        return $reloaded ?? $entry->withId($resultEntry['id'])->withSlug((string) $resultEntry['slug']);
    }

    public function delete(int $id): void
    {
        $this->connection->execute(
            'DELETE FROM entries WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Marks the entry as published. Sets `published_at` only if it was
     * previously NULL so a republished entry preserves its original
     * publish date. Does not write a revision.
     */
    public function publish(int $id): void
    {
        $now = self::now();
        $this->connection->execute(
            'UPDATE entries
                SET status = :status,
                    published_at = COALESCE(published_at, :published_at)
              WHERE id = :id',
            [
                'status' => self::STATUS_PUBLISHED,
                'published_at' => $now,
                'id' => $id,
            ],
        );
    }

    /**
     * Marks the entry as draft. Leaves `published_at` untouched so a
     * later publish keeps the original date. Does not write a revision.
     */
    public function unpublish(int $id): void
    {
        $this->connection->execute(
            'UPDATE entries SET status = :status WHERE id = :id',
            [
                'status' => self::STATUS_DRAFT,
                'id' => $id,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Entry
    {
        $id = (int) ($row['id'] ?? 0);
        $collectionId = (int) ($row['collection_id'] ?? 0);
        $slug = (string) ($row['slug'] ?? '');
        $status = (string) ($row['status'] ?? self::STATUS_DRAFT);
        $publishedAt = isset($row['published_at']) && $row['published_at'] !== null
            ? (string) $row['published_at']
            : null;
        $authorId = isset($row['author_id']) && $row['author_id'] !== null
            ? (int) $row['author_id']
            : null;
        $metaTitle = isset($row['meta_title']) && $row['meta_title'] !== null && $row['meta_title'] !== ''
            ? (string) $row['meta_title']
            : null;
        $metaDescription = isset($row['meta_description']) && $row['meta_description'] !== null && $row['meta_description'] !== ''
            ? (string) $row['meta_description']
            : null;
        $ogImageId = isset($row['og_image_id']) && $row['og_image_id'] !== null
            ? (int) $row['og_image_id']
            : null;
        $values = $this->loadValues($id);

        return new Entry($id, $collectionId, $slug, $values, $status, $publishedAt, $authorId, $metaTitle, $metaDescription, $ogImageId);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadValues(int $entryId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT entry_id, field_key, field_type, value, value_text, value_number, value_date, value_bool, value_json
               FROM entry_values WHERE entry_id = :entry_id',
            ['entry_id' => $entryId],
        );
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['field_type'] ?? 'text');
            $key = (string) ($row['field_key'] ?? '');
            if ($key === '' || !$this->fieldTypes->has($type)) {
                continue;
            }
            $typeHandler = $this->fieldTypes->get($type);
            $out[$key] = $typeHandler->bindForRead($row);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $payload
     */
    private function writeValues(int $entryId, Collection $collection, array $payload, array $fields, string $now): void
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($key === '' || $type === '' || !$this->fieldTypes->has($type)) {
                continue;
            }
            $typeHandler = $this->fieldTypes->get($type);
            $value = $payload[$key] ?? null;

            $bound = $typeHandler->bindForWrite($entryId, $key, $value);
            $row = [
                'entry_id' => $entryId,
                'field_key' => $key,
                'field_type' => $type,
                'value' => self::valueColumnSnapshot($bound),
                'value_index' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            foreach ($bound as $column => $cell) {
                $row[$column] = $cell;
            }

            $this->connection->execute(
                'INSERT INTO entry_values
                     (entry_id, field_key, field_type, value,
                      value_text, value_number, value_date, value_bool, value_json,
                      value_index, created_at, updated_at)
                 VALUES
                     (:entry_id, :field_key, :field_type, :value,
                      :value_text, :value_number, :value_date, :value_bool, :value_json,
                      :value_index, :created_at, :updated_at)',
                $row,
            );
        }
    }

    /**
     * Builds the pre-save snapshot stored in `revisions.payload`. Carries
     * the entry's slug, title, and current field values keyed by
     * `field_key`. Restore replays this through {@see save()}, so values
     * pass through the existing validation/binding path.
     *
     * @return array<string, mixed>
     */
    private function snapshotForRevision(Entry $entry, string $slugSourceKey): array
    {
        return [
            'slug' => $entry->slug(),
            'title' => (string) $entry->value($slugSourceKey),
            'values' => $entry->values(),
        ];
    }

    private function retentionLimit(): int
    {
        if ($this->config === null) {
            return 20;
        }
        return max(0, $this->config->getInt('content.revision_retention_limit', 20));
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function resolveSlugSourceKey(array $fields): string
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $key = (string) ($field['key'] ?? '');
            if (($type === 'text' || $type === 'richtext' || $type === 'select') && $key !== '') {
                return $key;
            }
        }
        return '';
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value) && isset($value['id'])) {
            return (string) $value['id'];
        }
        return '';
    }

    /**
     * @param array<string, mixed> $bound
     */
    private static function valueColumnSnapshot(array $bound): string
    {
        if (isset($bound['value_text']) && $bound['value_text'] !== null) {
            return (string) $bound['value_text'];
        }
        if (isset($bound['value_number']) && $bound['value_number'] !== null) {
            return (string) $bound['value_number'];
        }
        if (isset($bound['value_date']) && $bound['value_date'] !== null) {
            return (string) $bound['value_date'];
        }
        if (isset($bound['value_bool']) && $bound['value_bool'] !== null) {
            return $bound['value_bool'] ? '1' : '0';
        }
        if (isset($bound['value_json']) && $bound['value_json'] !== null) {
            return (string) $bound['value_json'];
        }
        return '';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function publicPath(string $collectionSlug, string $entrySlug): string
    {
        return '/' . ltrim($collectionSlug, '/') . '/' . ltrim($entrySlug, '/');
    }
}