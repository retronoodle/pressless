<?php

declare(strict_types=1);

namespace Stead\Media;

use Stead\Database\Connection;

/**
 * Reads and writes `media` rows.
 *
 * The repository is the only place media persistence touches SQL. Filesystem
 * layout is owned by {@see LocalStorage}; this class only handles the row
 * itself, including the relative `path` that {@see LocalStorage} will resolve
 * back to an absolute file location.
 */
final class MediaRepository
{
    public const PAGE_SIZE = 20;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Inserts a new `media` row and returns the populated {@see Media}.
     *
     * The caller is responsible for having stored the file on disk first via
     * {@see LocalStorage}; the `path` argument is the relative path that
     * was written (e.g. `5/photo.jpg`).
     *
     * @param array{filename: string, mime_type: string, size_bytes: int, path: string, uploaded_by: ?int} $data
     */
    public function create(array $data): Media
    {
        $now = self::now();
        $this->connection->execute(
            'INSERT INTO media (filename, mime_type, size_bytes, path, uploaded_by, created_at)
             VALUES (:filename, :mime_type, :size_bytes, :path, :uploaded_by, :created_at)',
            [
                'filename' => $data['filename'],
                'mime_type' => $data['mime_type'],
                'size_bytes' => $data['size_bytes'],
                'path' => $data['path'],
                'uploaded_by' => $data['uploaded_by'],
                'created_at' => $now,
            ],
        );
        $id = (int) $this->connection->fetchOne('SELECT last_insert_rowid() AS id')['id'];
        if ($id === 0) {
            $id = (int) $this->connection->fetchOne(
                'SELECT id FROM media WHERE filename = :f AND path = :p ORDER BY id DESC LIMIT 1',
                ['f' => $data['filename'], 'p' => $data['path']],
            )['id'];
        }
        $row = $this->connection->fetchOne('SELECT * FROM media WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new \Stead\Exception\SafeException('Media row could not be read back after creation.');
        }
        return Media::fromRow($row);
    }

    public function find(int $id): ?Media
    {
        $row = $this->connection->fetchOne('SELECT * FROM media WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Media::fromRow($row);
    }

    /**
     * @return list<Media>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAll('SELECT * FROM media ORDER BY id DESC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = Media::fromRow($row);
        }
        return $out;
    }

    /**
     * Returns one page of media rows ordered by id descending (newest first),
     * matching the listing convention used by the admin library.
     *
     * @return array{items: list<Media>, has_next: bool, total: int, page: int, page_size: int}
     */
    public function listPaged(int $page): array
    {
        $pageSize = self::PAGE_SIZE;
        $page = $page < 1 ? 1 : $page;
        $offset = ($page - 1) * $pageSize;

        $total = (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM media')['c'] ?? 0);

        $rows = $this->connection->fetchAll(
            'SELECT * FROM media ORDER BY id DESC LIMIT :limit OFFSET :offset',
            ['limit' => $pageSize, 'offset' => $offset],
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = Media::fromRow($row);
        }

        return [
            'items' => $items,
            'has_next' => ($offset + count($items)) < $total,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function count(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM media')['c'] ?? 0);
    }

    public function updatePath(int $id, string $path): void
    {
        $this->connection->execute(
            'UPDATE media SET path = :path WHERE id = :id',
            ['path' => $path, 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->connection->execute(
            'DELETE FROM media WHERE id = :id',
            ['id' => $id],
        );
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
