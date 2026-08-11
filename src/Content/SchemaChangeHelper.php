<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Applies the difference between a collection's previous and new field sets
 * to the `entry_values` table and records the result in `schema_change_log`.
 *
 * Diff cases (matched by field key, not by position):
 *
 *  - **Add**: a field appears in the new set but not the previous set → no
 *    DML is needed because the typed-uniform column layout does not depend
 *    on a fixed field list.
 *  - **Drop**: a field appears in the previous set but not the new set,
 *    and no rename pairing absorbed it → `DELETE FROM entry_values WHERE
 *    field_key = ? AND entry_id IN (entries of this collection)`.
 *  - **Type change**: both sets have the same key but its `type` differs →
 *    `DELETE` so the old typed value cannot be read as the new type.
 *  - **Rename**: a key was removed and a different key was added with the
 *    same `type`. Pairing is 1-to-1 by type group, ordered by key. When
 *    the counts of removed and added keys of the same type do not match,
 *    the helper does not infer renames and treats the removed keys as
 *    drops. `UPDATE entry_values SET field_key = ? WHERE field_key = ?`.
 *
 * Fields that appear in both with the same key and type — including
 * pure reorders — are no-ops.
 *
 * Idempotence: `apply()` short-circuits when the new field-set hash matches
 * the most recently logged hash for the collection, so a no-op re-save is a
 * no-op.
 */
final class SchemaChangeHelper
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<array<string, mixed>> $previousFields
     * @param list<array<string, mixed>> $newFields
     */
    public function apply(int $collectionId, array $previousFields, array $newFields): void
    {
        $previousFields = self::normalizeList($previousFields);
        $newFields = self::normalizeList($newFields);

        $newHash = self::hashFields($newFields);
        $previousHash = $this->lastAppliedHash($collectionId);

        if ($previousHash !== null && $previousHash === $newHash) {
            return;
        }

        if ($previousFields === $newFields) {
            return;
        }

        $this->connection->transaction(function () use (
            $collectionId,
            $previousFields,
            $newFields,
            $previousHash,
            $newHash,
        ): void {
            $this->applyDeltas($collectionId, $previousFields, $newFields);
            $this->recordLog($collectionId, $previousHash, $newHash);
        });
    }

    /**
     * Computes the stable hash of a field set. Exposed so callers (the
     * seeder, tests) can correlate logs with schema definitions.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function hashFields(array $fields): string
    {
        return sha1(self::canonicalize($fields));
    }

    /**
     * @param list<array<string, mixed>> $previous
     * @param list<array<string, mixed>> $current
     */
    private function applyDeltas(int $collectionId, array $previous, array $current): void
    {
        $prevByKey = [];
        foreach ($previous as $field) {
            $prevByKey[(string) $field['key']] = $field;
        }
        $newByKey = [];
        foreach ($current as $field) {
            $newByKey[(string) $field['key']] = $field;
        }

        foreach ($prevByKey as $key => $prevField) {
            if (!isset($newByKey[$key])) {
                continue;
            }
            if ((string) $prevField['type'] === (string) $newByKey[$key]['type']) {
                continue;
            }
            $this->dropField($collectionId, $key);
        }

        $removed = [];
        foreach ($prevByKey as $key => $field) {
            if (!isset($newByKey[$key])) {
                $removed[$key] = $field;
            }
        }
        $added = [];
        foreach ($newByKey as $key => $field) {
            if (!isset($prevByKey[$key])) {
                $added[$key] = $field;
            }
        }

        $removedByType = [];
        foreach ($removed as $key => $field) {
            $removedByType[(string) $field['type']][] = $key;
        }
        $addedByType = [];
        foreach ($added as $key => $field) {
            $addedByType[(string) $field['type']][] = $key;
        }

        $renames = [];
        foreach ($removedByType as $type => $oldKeys) {
            if (!isset($addedByType[$type])) {
                continue;
            }
            $newKeys = $addedByType[$type];
            if (count($oldKeys) !== count($newKeys)) {
                continue;
            }
            sort($oldKeys);
            sort($newKeys);
            foreach ($oldKeys as $i => $oldKey) {
                $renames[$oldKey] = $newKeys[$i];
            }
        }

        foreach ($renames as $oldKey => $newKey) {
            $this->renameField($collectionId, $oldKey, $newKey);
        }

        foreach ($removed as $key => $field) {
            if (isset($renames[$key])) {
                continue;
            }
            $this->dropField($collectionId, $key);
        }
    }

    private function dropField(int $collectionId, string $fieldKey): void
    {
        $this->connection->execute(
            'DELETE FROM entry_values
             WHERE field_key = :field_key
               AND entry_id IN (SELECT id FROM entries WHERE collection_id = :collection_id)',
            [
                'field_key' => $fieldKey,
                'collection_id' => $collectionId,
            ],
        );
    }

    private function renameField(int $collectionId, string $oldKey, string $newKey): void
    {
        $this->connection->execute(
            'UPDATE entry_values
                SET field_key = :new_key
              WHERE field_key = :old_key
                AND entry_id IN (SELECT id FROM entries WHERE collection_id = :collection_id)',
            [
                'new_key' => $newKey,
                'old_key' => $oldKey,
                'collection_id' => $collectionId,
            ],
        );
    }

    private function recordLog(int $collectionId, ?string $previousHash, string $newHash): void
    {
        $this->connection->execute(
            'INSERT INTO schema_change_log (collection_id, previous_field_set_hash, new_field_set_hash, applied_at)
             VALUES (:collection_id, :previous_hash, :new_hash, :applied_at)',
            [
                'collection_id' => $collectionId,
                'previous_hash' => $previousHash,
                'new_hash' => $newHash,
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ],
        );
    }

    private function lastAppliedHash(int $collectionId): ?string
    {
        $row = $this->connection->fetchOne(
            'SELECT new_field_set_hash FROM schema_change_log
              WHERE collection_id = :collection_id
              ORDER BY applied_at DESC, id DESC
              LIMIT 1',
            ['collection_id' => $collectionId],
        );
        if ($row === null) {
            return null;
        }
        $hash = $row['new_field_set_hash'] ?? null;
        return is_string($hash) ? $hash : null;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return list<array{key: string, type: string, label: string}>
     */
    private static function normalizeList(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = isset($field['key']) && is_string($field['key']) ? $field['key'] : '';
            $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : '';
            $label = isset($field['label']) && is_string($field['label']) ? $field['label'] : $key;
            if ($key === '' || $type === '') {
                continue;
            }
            $out[] = ['key' => $key, 'type' => $type, 'label' => $label];
        }
        return $out;
    }

    /**
     * @param list<array{key: string, type: string, label: string}> $fields
     */
    private static function canonicalize(array $fields): string
    {
        $copy = $fields;
        usort($copy, static fn(array $a, array $b) => strcmp($a['key'], $b['key']));
        $normalized = [];
        foreach ($copy as $field) {
            $normalized[] = [
                'key' => $field['key'],
                'type' => $field['type'],
                'label' => $field['label'],
            ];
        }
        return (string) json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the fields of a collection schema by their position so the
     * helper does not depend on the {@see Collection} value object.
     *
     * @param array{fields: list<array<string, mixed>>} $schema
     * @return list<array<string, mixed>>
     */
    public static function fieldsOfSchema(array $schema): array
    {
        return is_array($schema['fields'] ?? null) ? array_values($schema['fields']) : [];
    }
}
