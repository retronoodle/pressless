<?php

declare(strict_types=1);

namespace Stead\Content;

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
    public function __construct(
        private readonly Connection $connection,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly SlugGenerator $slugs,
    ) {
    }

    public function find(int $id): ?Entry
    {
        $row = $this->connection->fetchOne(
            'SELECT id, collection_id, slug, title FROM entries WHERE id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function findByCollectionAndSlug(int $collectionId, string $slug): ?Entry
    {
        $row = $this->connection->fetchOne(
            'SELECT id, collection_id, slug, title FROM entries
              WHERE collection_id = :collection_id AND slug = :slug',
            ['collection_id' => $collectionId, 'slug' => $slug],
        );
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @return list<Entry>
     */
    public function listByCollection(int $collectionId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, collection_id, slug, title FROM entries
              WHERE collection_id = :collection_id ORDER BY id ASC',
            ['collection_id' => $collectionId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function count(): int
    {
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM entries');
        return (int) ($row['c'] ?? 0);
    }

    public function countByCollection(int $collectionId): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM entries WHERE collection_id = :collection_id',
            ['collection_id' => $collectionId],
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Persists the entry and all of its typed values inside a single
     * transaction. The slug source field is detected from the collection's
     * schema (first text-like field if none is marked) and the slug is
     * regenerated only when that source field changes; unrelated edits
     * preserve the existing slug.
     *
     * @param array<string, mixed> $payload map of field_key => submitted value
     */
    public function save(Entry $entry, Collection $collection, array $payload): Entry
    {
        $fields = $collection->fields();
        $now = self::now();
        $slugSourceKey = $this->resolveSlugSourceKey($fields);
        $slugSourceRaw = $payload[$slugSourceKey] ?? null;
        $slugSourceString = $this->stringify($slugSourceRaw);

        $resultEntry = $this->connection->transaction(function () use (
            $entry,
            $collection,
            $payload,
            $fields,
            $slugSourceKey,
            $slugSourceString,
            $now,
        ): array {
            if ($entry->id() === 0) {
                $slug = $this->slugs->uniqueForCollection(
                    $this->slugs->generate($slugSourceString),
                    $collection->id(),
                );
                $title = $slugSourceString !== '' ? $slugSourceString : $slug;

                $this->connection->execute(
                    'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
                     VALUES (:collection_id, :slug, :title, :status, :created_at, :updated_at)',
                    [
                        'collection_id' => $collection->id(),
                        'slug' => $slug,
                        'title' => $title,
                        'status' => 'published',
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

                $existingSource = $existing->value($slugSourceKey);
                $sourceChanged = $this->stringify($existingSource) !== $slugSourceString;

                if ($sourceChanged) {
                    $slug = $this->slugs->uniqueForCollection(
                        $this->slugs->generate($slugSourceString),
                        $collection->id(),
                        $entryId,
                    );
                } else {
                    $slug = $existing->slug();
                }
                $title = $slugSourceString !== '' ? $slugSourceString : $slug;

                $this->connection->execute(
                    'UPDATE entries SET slug = :slug, title = :title, updated_at = :updated_at WHERE id = :id',
                    [
                        'slug' => $slug,
                        'title' => $title,
                        'updated_at' => $now,
                        'id' => $entryId,
                    ],
                );

                $this->connection->execute(
                    'DELETE FROM entry_values WHERE entry_id = :entry_id',
                    ['entry_id' => $entryId],
                );
            }

            $this->writeValues($entryId, $collection, $payload, $fields, $now);

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
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Entry
    {
        $id = (int) ($row['id'] ?? 0);
        $collectionId = (int) ($row['collection_id'] ?? 0);
        $slug = (string) ($row['slug'] ?? '');
        $values = $this->loadValues($id);

        return new Entry($id, $collectionId, $slug, $values);
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
}
