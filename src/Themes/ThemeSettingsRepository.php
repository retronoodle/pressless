<?php

declare(strict_types=1);

namespace Stead\Themes;

use Stead\Database\Connection;

/**
 * Stores per-theme setting values keyed by theme slug and setting key.
 *
 * Keys are namespaced by slug (not theme id) so values survive a
 * delete-then-re-upload cycle for the same slug — see the design doc.
 * Removed-from-the-manifest keys are kept dormant in the table;
 * {@see valuesFor()} only returns the keys present in the supplied
 * schema, so a dormant key is invisible until the manifest re-declares
 * it.
 */
final class ThemeSettingsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Returns each schema entry's stored value (string-cast), falling
     * back to the schema's `default` when no row exists. Keys present
     * in the database but not in the schema (dormant) are not returned.
     *
     * @param list<array{key: string, label: string, type: string, default: string, options: list<string>}> $schema
     * @return array<string, string>
     */
    public function valuesFor(string $slug, array $schema): array
    {
        $stored = $this->loadAllForSlug($slug);
        $out = [];
        foreach ($schema as $entry) {
            $key = $entry['key'];
            if (array_key_exists($key, $stored)) {
                $out[$key] = (string) $stored[$key];
            } else {
                $out[$key] = (string) ($entry['default'] ?? '');
            }
        }
        return $out;
    }

    /**
     * Upserts each `(slug, key) => value` pair. Existing rows for keys
     * not in `$values` are left untouched (dormant retention).
     *
     * @param array<string, string> $values
     */
    public function save(string $slug, array $values): void
    {
        if ($values === []) {
            return;
        }
        $this->connection->transaction(function () use ($slug, $values): void {
            foreach ($values as $key => $value) {
                $this->upsert($slug, (string) $key, (string) $value);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function loadAllForSlug(string $slug): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT setting_key, value FROM theme_settings WHERE theme_slug = :slug',
            ['slug' => $slug],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) $row['value'];
        }
        return $out;
    }

    private function upsert(string $slug, string $key, string $value): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->connection->fetchOne(
            'SELECT id FROM theme_settings WHERE theme_slug = :slug AND setting_key = :key',
            ['slug' => $slug, 'key' => $key],
        );
        if ($existing === null) {
            $this->connection->execute(
                'INSERT INTO theme_settings (theme_slug, setting_key, value, created_at, updated_at)
                 VALUES (:slug, :key, :value, :created_at, :updated_at)',
                [
                    'slug' => $slug,
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            return;
        }
        $this->connection->execute(
            'UPDATE theme_settings
                SET value = :value,
                    updated_at = :updated_at
              WHERE id = :id',
            [
                'value' => $value,
                'updated_at' => $now,
                'id' => (int) $existing['id'],
            ],
        );
    }
}
