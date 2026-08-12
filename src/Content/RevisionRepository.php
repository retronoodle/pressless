<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Database\Connection;

/**
 * Reads and writes `revisions` rows.
 *
 * A revision captures an entry's pre-save state (slug, title, and the
 * raw field values keyed by `field_key`) so the admin surface can show
 * history and restore an earlier version. The repository is the only
 * place SQL touches the revisions table; pruning is bounded by
 * `content.revision_retention_limit` and happens at the end of the
 * save transaction in {@see EntryRepository}.
 */
final class RevisionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Writes a new revision row and returns its id. `payload` should be
     * a JSON-encodable map of `slug`, `title`, and `field_key => value`.
     *
     * @param array<string, mixed> $payload
     */
    public function save(int $entryId, array $payload, ?int $authorId): int
    {
        $now = self::now();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (!is_string($json)) {
            $json = '{}';
        }

        $this->connection->execute(
            'INSERT INTO revisions (entry_id, author_id, payload, created_at)
             VALUES (:entry_id, :author_id, :payload, :created_at)',
            [
                'entry_id' => $entryId,
                'author_id' => $authorId,
                'payload' => $json,
                'created_at' => $now,
            ],
        );

        $row = $this->connection->fetchOne('SELECT id FROM revisions WHERE entry_id = :entry_id ORDER BY id DESC LIMIT 1', [
            'entry_id' => $entryId,
        ]);
        return $row === null ? 0 : (int) $row['id'];
    }

    /**
     * Returns revisions for the entry, newest first. Each row includes
     * `id`, `entry_id`, `author_id`, `payload` (decoded), `created_at`,
     * and `author_name` (resolved via LEFT JOIN when available).
     *
     * @return list<array<string, mixed>>
     */
    public function listByEntry(int $entryId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT r.id, r.entry_id, r.author_id, r.payload, r.created_at, u.name AS author_name
               FROM revisions r
               LEFT JOIN users u ON u.id = r.author_id
              WHERE r.entry_id = :entry_id
              ORDER BY r.created_at DESC, r.id DESC',
            ['entry_id' => $entryId],
        );

        $out = [];
        foreach ($rows as $row) {
            $payload = $row['payload'] ?? '{}';
            if (!is_string($payload)) {
                $payload = '{}';
            }
            $decoded = json_decode($payload, true);
            $row['payload'] = is_array($decoded) ? $decoded : [];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Returns the most recent revisions across all entries, joined with the
     * entry title, the parent collection slug/name, and the editor's display
     * name. Used by the admin dashboard's recent-activity feed.
     *
     * Each row carries: `id`, `entry_id`, `entry_title`, `entry_slug`,
     * `collection_slug`, `collection_name`, `author_id`, `author_name`,
     * `created_at`, and `payload` (decoded to a map).
     *
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT r.id, r.entry_id, r.author_id, r.payload, r.created_at,
                    u.name AS author_name,
                    e.title AS entry_title, e.slug AS entry_slug,
                    c.slug AS collection_slug, c.name AS collection_name
               FROM revisions r
               LEFT JOIN entries e ON e.id = r.entry_id
               LEFT JOIN collections c ON c.id = e.collection_id
               LEFT JOIN users u ON u.id = r.author_id
              ORDER BY r.created_at DESC, r.id DESC
              LIMIT :limit',
            ['limit' => $limit],
        );

        $out = [];
        foreach ($rows as $row) {
            $payload = $row['payload'] ?? '{}';
            if (!is_string($payload)) {
                $payload = '{}';
            }
            $decoded = json_decode($payload, true);
            $row['payload'] = is_array($decoded) ? $decoded : [];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Returns one revision with its decoded payload, or null if missing.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $revisionId): ?array
    {
        $row = $this->connection->fetchOne(
            'SELECT id, entry_id, author_id, payload, created_at FROM revisions WHERE id = :id',
            ['id' => $revisionId],
        );
        if ($row === null) {
            return null;
        }
        $payload = $row['payload'] ?? '{}';
        if (!is_string($payload)) {
            $payload = '{}';
        }
        $decoded = json_decode($payload, true);
        $row['payload'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /**
     * Deletes the oldest revisions for the entry beyond `$keep`. Portable
     * across MySQL/SQLite via a fetch-then-delete loop (avoids DELETE
     * with subquery LIMIT portability quirks).
     */
    public function pruneOldest(int $entryId, int $keep): void
    {
        if ($keep < 0) {
            $keep = 0;
        }
        $countRow = $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM revisions WHERE entry_id = :entry_id',
            ['entry_id' => $entryId],
        );
        $total = (int) ($countRow['c'] ?? 0);
        $excess = $total - $keep;
        if ($excess <= 0) {
            return;
        }

        $oldest = $this->connection->fetchAll(
            'SELECT id FROM revisions
              WHERE entry_id = :entry_id
              ORDER BY created_at ASC, id ASC
              LIMIT :limit',
            ['entry_id' => $entryId, 'limit' => $excess],
        );
        foreach ($oldest as $row) {
            $this->connection->execute(
                'DELETE FROM revisions WHERE id = :id',
                ['id' => (int) $row['id']],
            );
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}