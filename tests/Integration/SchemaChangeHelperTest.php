<?php

declare(strict_types=1);

namespace Pressless\Tests\Integration;

use Pressless\Content\Collection;
use Pressless\Content\CollectionRepository;
use Pressless\Content\SchemaChangeHelper;

/**
 * End-to-end coverage of SchemaChangeHelper against the real schema,
 * exercising each diff case + idempotence + transactional rollback.
 *
 * SQLite-only so the seeded `entry_values` rows stay readable across
 * tests; MySQL/MariaDB syntax differs for `(SELECT … IN)` and the unique
 * constraints.
 */
final class SchemaChangeHelperTest extends DatabaseTestCase
{
    protected static function driver(): string
    {
        return 'sqlite';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function setUpFixture(): array
    {
        $this->installFullMigrations();
        $this->migrator->migrate();

        $now = gmdate('Y-m-d H:i:s');

        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            ['posts', 'Posts', json_encode(['fields' => []]), $now, $now],
        );
        $collectionId = (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = ?', ['posts'])['id'];

        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$collectionId, 'hello', 'Hello', 'draft', $now, $now],
        );
        $entryId = (int) $this->connection->fetchOne('SELECT id FROM entries WHERE slug = ?', ['hello'])['id'];

        return [$collectionId, $entryId];
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

    /**
     * @param array<string, mixed> $typedValues
     */
    private function insertValue(int $entryId, string $key, string $fieldType, array $typedValues): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $valueText = isset($typedValues['value_text']) && $typedValues['value_text'] !== null
            ? (string) $typedValues['value_text']
            : (isset($typedValues['value_number']) && is_scalar($typedValues['value_number'])
                ? (string) $typedValues['value_number']
                : (isset($typedValues['value_date']) && is_scalar($typedValues['value_date'])
                    ? (string) $typedValues['value_date']
                    : ''));

        $this->connection->execute(
            'INSERT INTO entry_values
                 (entry_id, field_key, field_type, value,
                  value_text, value_number, value_date, value_bool, value_json,
                  value_index, created_at, updated_at)
             VALUES
                 (:entry_id, :field_key, :field_type, :value,
                  :value_text, :value_number, :value_date, :value_bool, :value_json,
                  :value_index, :ts, :ts)',
            [
                'entry_id' => $entryId,
                'field_key' => $key,
                'field_type' => $fieldType,
                'value' => $valueText,
                'value_text' => $typedValues['value_text'] ?? null,
                'value_number' => $typedValues['value_number'] ?? null,
                'value_date' => $typedValues['value_date'] ?? null,
                'value_bool' => $typedValues['value_bool'] ?? null,
                'value_json' => $typedValues['value_json'] ?? null,
                'value_index' => '',
                'ts' => $now,
            ],
        );
    }

    private function hasValueForField(int $entryId, string $key): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM entry_values WHERE entry_id = :entry_id AND field_key = :field_key',
            ['entry_id' => $entryId, 'field_key' => $key],
        );
        return ((int) $row['c']) > 0;
    }

    private function valueOf(int $entryId, string $key): ?string
    {
        $row = $this->connection->fetchOne(
            'SELECT value_text FROM entry_values WHERE entry_id = :entry_id AND field_key = :field_key',
            ['entry_id' => $entryId, 'field_key' => $key],
        );
        return $row === null ? null : (string) $row['value_text'];
    }

    public function testAddFieldWritesNoValues(): void
    {
        [$collectionId] = $this->setUpFixture();
        $helper = new SchemaChangeHelper($this->connection);

        $previous = [['key' => 'title', 'type' => 'text', 'label' => 'Title']];
        $current = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
        ];

        $helper->apply($collectionId, $previous, $current);

        $this->assertSame(1, $this->logCount($collectionId), 'add field must record one log row.');
        $hashes = $this->lastLogHashes($collectionId);
        $this->assertSame('', $hashes['previous'], 'first apply has no prior hash.');
        $this->assertNotSame('', $hashes['new']);
        $this->assertNotSame(SchemaChangeHelper::hashFields($previous), $hashes['new']);
    }

    public function testDropFieldDeletesEntryValuesForThatField(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'title', 'text', ['value_text' => 'Hello', 'value' => 'Hello']);
        $this->insertValue($entryId, 'body', 'text', ['value_text' => 'World', 'value' => 'World']);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
        ];
        $current = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ];

        $helper->apply($collectionId, $previous, $current);

        $this->assertFalse($this->hasValueForField($entryId, 'body'));
        $this->assertSame('Hello', $this->valueOf($entryId, 'title'));
        $this->assertSame(1, $this->logCount($collectionId));
    }

    public function testRenameFieldRewritesFieldKey(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'title', 'text', ['value_text' => 'Hello', 'value' => 'Hello']);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [['key' => 'title', 'type' => 'text', 'label' => 'Title']];
        $current = [['key' => 'headline', 'type' => 'text', 'label' => 'Headline']];

        $helper->apply($collectionId, $previous, $current);

        $this->assertFalse($this->hasValueForField($entryId, 'title'));
        $this->assertTrue($this->hasValueForField($entryId, 'headline'));
        $this->assertSame('Hello', $this->valueOf($entryId, 'headline'));
        $this->assertSame(1, $this->logCount($collectionId));
    }

    public function testTypeChangeRemovesTheTypedRowsForThatField(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'rating', 'number', [
            'value_number' => 4.5,
            'value_text' => '4.5',
            'value' => '4.5',
        ]);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [['key' => 'rating', 'type' => 'number', 'label' => 'Rating']];
        $current = [['key' => 'rating', 'type' => 'text', 'label' => 'Rating']];

        $helper->apply($collectionId, $previous, $current);

        $this->assertFalse(
            $this->hasValueForField($entryId, 'rating'),
            'typed rows must be cleared so the new text field does not read a stale number.',
        );
        $this->assertSame(1, $this->logCount($collectionId));
    }

    public function testApplyIsIdempotentForTheSameNewFieldSet(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'title', 'text', ['value_text' => 'Hello']);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [];
        $current = [['key' => 'title', 'type' => 'text', 'label' => 'Title']];

        $helper->apply($collectionId, $previous, $current);
        $helper->apply($collectionId, $previous, $current);
        $helper->apply($collectionId, $previous, $current);

        $this->assertSame(1, $this->logCount($collectionId), 'a no-op re-apply must not record a log row.');
        $this->assertSame('Hello', $this->valueOf($entryId, 'title'));
    }

    public function testDropFailureRollsBackTheTransaction(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'title', 'text', ['value_text' => 'Hello']);
        $this->insertValue($entryId, 'body', 'text', ['value_text' => 'World']);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
        ];
        $current = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ];

        $this->connection->execute('DROP TABLE entry_values');

        try {
            $helper->apply($collectionId, $previous, $current);
            $this->fail('Expected exception when entry_values is missing.');
        } catch (\Throwable) {
        }

        $this->assertSame(0, $this->logCount($collectionId), 'failed transaction must leave no log row.');
    }

    public function testRemoveMiddleFieldDoesNotTouchOtherFieldValues(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'title', 'text', ['value_text' => 'Hello', 'value' => 'Hello']);
        $this->insertValue($entryId, 'body', 'text', ['value_text' => 'World', 'value' => 'World']);
        $this->insertValue($entryId, 'footer', 'text', ['value_text' => 'Bottom', 'value' => 'Bottom']);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
            ['key' => 'footer', 'type' => 'text', 'label' => 'Footer'],
        ];
        $current = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'footer', 'type' => 'text', 'label' => 'Footer'],
        ];

        $helper->apply($collectionId, $previous, $current);

        $this->assertTrue($this->hasValueForField($entryId, 'title'), 'title value must survive a middle removal.');
        $this->assertFalse($this->hasValueForField($entryId, 'body'), 'the removed field must be dropped.');
        $this->assertTrue($this->hasValueForField($entryId, 'footer'), 'footer value must survive a middle removal.');
        $this->assertSame('Hello', $this->valueOf($entryId, 'title'));
        $this->assertSame('Bottom', $this->valueOf($entryId, 'footer'));
        $this->assertSame(1, $this->logCount($collectionId));
    }

    public function testReorderFieldsPreservesValuesOnTheirKeys(): void
    {
        [$collectionId, $entryId] = $this->setUpFixture();
        $this->insertValue($entryId, 'title', 'text', ['value_text' => 'Hello']);
        $this->insertValue($entryId, 'body', 'text', ['value_text' => 'World']);

        $helper = new SchemaChangeHelper($this->connection);
        $previous = [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
        ];
        $current = [
            ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ];

        $helper->apply($collectionId, $previous, $current);

        $this->assertSame('Hello', $this->valueOf($entryId, 'title'), 'title key must keep its value across a reorder.');
        $this->assertSame('World', $this->valueOf($entryId, 'body'), 'body key must keep its value across a reorder.');
        $this->assertSame(1, $this->logCount($collectionId), 'reorder still records one log row.');
    }

    public function testEmptyPreviousFieldSetLogsTheNewHash(): void
    {
        [$collectionId] = $this->setUpFixture();
        $helper = new SchemaChangeHelper($this->connection);

        $helper->apply($collectionId, [], [['key' => 'title', 'type' => 'text', 'label' => 'Title']]);

        $this->assertSame(1, $this->logCount($collectionId));
        $row = $this->connection->fetchOne(
            'SELECT previous_field_set_hash, new_field_set_hash FROM schema_change_log WHERE collection_id = ?',
            [$collectionId],
        );
        $this->assertNull($row['previous_field_set_hash']);
        $this->assertSame(SchemaChangeHelper::hashFields([['key' => 'title', 'type' => 'text', 'label' => 'Title']]), $row['new_field_set_hash']);
    }

    private function logCount(int $collectionId): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM schema_change_log WHERE collection_id = :collection_id',
            ['collection_id' => $collectionId],
        );
        return (int) $row['c'];
    }

    /**
     * @return array<string, string>
     */
    private function lastLogHashes(int $collectionId): array
    {
        $row = $this->connection->fetchOne(
            'SELECT previous_field_set_hash, new_field_set_hash FROM schema_change_log
              WHERE collection_id = :collection_id
              ORDER BY applied_at DESC, id DESC LIMIT 1',
            ['collection_id' => $collectionId],
        );
        return [
            'previous' => (string) ($row['previous_field_set_hash'] ?? ''),
            'new' => (string) ($row['new_field_set_hash'] ?? ''),
        ];
    }
}
