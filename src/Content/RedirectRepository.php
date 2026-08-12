<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Database\Connection;

/**
 * Reads and writes `redirects` rows.
 *
 * `old_path` is unique. {@see upsert()} replaces the existing row's
 * `new_path` rather than creating a duplicate so a redirect can be
 * re-targeted by the slug-change path or by an admin edit.
 */
final class RedirectRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByOldPath(string $oldPath): ?Redirect
    {
        $row = $this->connection->fetchOne(
            'SELECT id, old_path, new_path, created_at, updated_at FROM redirects WHERE old_path = :old_path',
            ['old_path' => $oldPath],
        );
        return $row === null ? null : Redirect::fromRow($row);
    }

    /**
     * Inserts a row, or replaces the existing row's `new_path` if a row
     * already exists for the given `old_path`. Uses database-specific
     * upsert syntax so MySQL and SQLite both behave the same way.
     */
    public function upsert(string $oldPath, string $newPath): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $driver = $this->connection->driver();
        if ($driver === 'mysql') {
            $this->connection->execute(
                'INSERT INTO redirects (old_path, new_path, created_at, updated_at)
                 VALUES (:old_path, :new_path, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                     new_path = VALUES(new_path),
                     updated_at = VALUES(updated_at)',
                [
                    'old_path' => $oldPath,
                    'new_path' => $newPath,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            return;
        }
        $existing = $this->findByOldPath($oldPath);
        if ($existing === null) {
            $this->connection->execute(
                'INSERT INTO redirects (old_path, new_path, created_at, updated_at)
                 VALUES (:old_path, :new_path, :created_at, :updated_at)',
                [
                    'old_path' => $oldPath,
                    'new_path' => $newPath,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            return;
        }
        $this->connection->execute(
            'UPDATE redirects SET new_path = :new_path, updated_at = :updated_at WHERE old_path = :old_path',
            [
                'new_path' => $newPath,
                'updated_at' => $now,
                'old_path' => $oldPath,
            ],
        );
    }

    public function delete(int $id): void
    {
        $this->connection->execute(
            'DELETE FROM redirects WHERE id = :id',
            ['id' => $id],
        );
    }

    public function deleteByOldPath(string $oldPath): void
    {
        $this->connection->execute(
            'DELETE FROM redirects WHERE old_path = :old_path',
            ['old_path' => $oldPath],
        );
    }

    /**
     * @return list<Redirect>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, old_path, new_path, created_at, updated_at FROM redirects ORDER BY id ASC',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = Redirect::fromRow($row);
        }
        return $out;
    }
}