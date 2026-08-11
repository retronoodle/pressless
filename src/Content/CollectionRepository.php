<?php

declare(strict_types=1);

namespace Pressless\Content;

use Pressless\Database\Connection;
use Pressless\Exception\SafeException;

/**
 * Reads and writes `collections` rows.
 *
 * The repository is the only place SQL touches the collections table. Schema
 * validation is delegated to {@see CollectionSchemaValidator} so this class
 * can stay focused on persistence; callers are expected to validate before
 * calling {@see save()}.
 */
final class CollectionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): ?Collection
    {
        $row = $this->connection->fetchOne(
            'SELECT id, slug, name, schema_definition FROM collections WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : Collection::fromRow($row);
    }

    public function findBySlug(string $slug): ?Collection
    {
        $row = $this->connection->fetchOne(
            'SELECT id, slug, name, schema_definition FROM collections WHERE slug = :slug',
            ['slug' => $slug],
        );
        return $row === null ? null : Collection::fromRow($row);
    }

    /**
     * @return list<Collection>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, slug, name, schema_definition FROM collections ORDER BY slug ASC',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = Collection::fromRow($row);
        }
        return $out;
    }

    public function count(): int
    {
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections');
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Inserts when the collection's id is zero, otherwise updates the
     * existing row. The schema is JSON-encoded verbatim.
     */
    public function save(Collection $collection): Collection
    {
        $now = self::now();
        $schema = $collection->encodeSchema();

        if ($collection->id() === 0) {
            try {
                $this->connection->execute(
                    'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
                     VALUES (:slug, :name, :schema_definition, :created_at, :updated_at)',
                    [
                        'slug' => $collection->slug(),
                        'name' => $collection->name(),
                        'schema_definition' => $schema,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            } catch (\Throwable $e) {
                throw $this->translateIntegrityError($e, $collection->slug());
            }

            $row = $this->connection->fetchOne(
                'SELECT id FROM collections WHERE slug = :slug',
                ['slug' => $collection->slug()],
            );
            if ($row === null) {
                throw new SafeException('Collection could not be read back after creation.');
            }

            return $collection->withId((int) $row['id']);
        }

        try {
            $this->connection->execute(
                'UPDATE collections SET slug = :slug, name = :name, schema_definition = :schema_definition, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'slug' => $collection->slug(),
                    'name' => $collection->name(),
                    'schema_definition' => $schema,
                    'updated_at' => $now,
                    'id' => $collection->id(),
                ],
            );
        } catch (\Throwable $e) {
            throw $this->translateIntegrityError($e, $collection->slug());
        }

        return $collection;
    }

    public function delete(int $id): void
    {
        $this->connection->execute(
            'DELETE FROM collections WHERE id = :id',
            ['id' => $id],
        );
    }

    public function existsBySlug(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM collections WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return $this->connection->fetchOne($sql, $params) !== null;
    }

    private function translateIntegrityError(\Throwable $previous, string $slug): SafeException
    {
        $message = $previous->getMessage();
        if (str_contains($message, 'Duplicate') || str_contains($message, 'UNIQUE')) {
            return new SafeException(
                sprintf('A collection with slug "%s" already exists.', $slug),
                ['slug' => $slug],
                $previous,
            );
        }
        if ($previous instanceof SafeException) {
            return $previous;
        }
        return new SafeException('Could not save collection.', ['slug' => $slug], $previous);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
