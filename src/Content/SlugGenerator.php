<?php

declare(strict_types=1);

namespace Pressless\Content;

use Pressless\Database\Connection;

/**
 * Produces and de-duplicates entry slugs.
 *
 * `generate()` is pure: lowercases, swaps non-alphanumerics for `-`, trims,
 * and collapses runs. `uniqueForCollection()` appends `-2`, `-3`, … until a
 * free slug is found within the collection, optionally excluding an entry
 * (so an unchanged re-save does not collide with itself).
 */
final class SlugGenerator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function generate(string $sourceValue): string
    {
        $lowered = strtolower($sourceValue);
        $collapsed = (string) preg_replace('/[^a-z0-9]+/', '-', $lowered);
        return trim($collapsed, '-');
    }

    /**
     * Returns `$base` when free, otherwise the first suffixed slug
     * (`-2`, `-3`, …) that is not taken by any other entry in the
     * collection. `$excludeEntryId` lets the caller skip itself.
     */
    public function uniqueForCollection(string $base, int $collectionId, ?int $excludeEntryId = null): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'entry';
        }

        if (!$this->slugExists($collectionId, $base, $excludeEntryId)) {
            return $base;
        }

        $suffix = 2;
        while (true) {
            $candidate = $base . '-' . $suffix;
            if (!$this->slugExists($collectionId, $candidate, $excludeEntryId)) {
                return $candidate;
            }
            $suffix++;
            if ($suffix > 10000) {
                return $base . '-' . bin2hex(random_bytes(4));
            }
        }
    }

    private function slugExists(int $collectionId, string $slug, ?int $excludeEntryId): bool
    {
        $sql = 'SELECT id FROM entries WHERE collection_id = :collection_id AND slug = :slug';
        $params = [
            'collection_id' => $collectionId,
            'slug' => $slug,
        ];
        if ($excludeEntryId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeEntryId;
        }
        return $this->connection->fetchOne($sql, $params) !== null;
    }
}
