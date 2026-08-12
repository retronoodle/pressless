<?php

declare(strict_types=1);

namespace Stead\Backups;

use Stead\Database\Connection;

/**
 * Tracks backup runs in the `backups` table.
 *
 * Mirrors the repository style used elsewhere in Stead: thin, raw-SQL,
 * no ORM. Each backup row records enough state (target + storage key,
 * size, status, trigger source, error) for the admin UI and retention
 * pruning to do their job without re-listing the storage target.
 */
final class BackupRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(string $target, string $storageKey, string $triggeredBy): Backup
    {
        $driver = $this->connection->driver();
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO backups (target, storage_key, size_bytes, status, triggered_by, error_message, created_at) '
            . 'VALUES (:target, :storage_key, :size_bytes, :status, :triggered_by, :error_message, :created_at)',
            [
                'target' => $target,
                'storage_key' => $storageKey,
                'size_bytes' => 0,
                'status' => 'pending',
                'triggered_by' => $triggeredBy,
                'error_message' => null,
                'created_at' => $now,
            ],
        );

        $id = (int) $this->connection->pdo()->lastInsertId();

        return new Backup(
            id: $id,
            target: $target,
            storageKey: $storageKey,
            sizeBytes: 0,
            status: 'pending',
            triggeredBy: $triggeredBy,
            errorMessage: null,
            createdAt: $now,
            context: ['db_driver' => $driver, 'media_root' => '', 'app_version' => ''],
        );
    }

    public function markSuccess(int $id, int $sizeBytes): void
    {
        $this->connection->execute(
            'UPDATE backups SET status = :status, size_bytes = :size_bytes, error_message = NULL WHERE id = :id',
            [
                'status' => BackupStatus::SUCCESS,
                'size_bytes' => $sizeBytes,
                'id' => $id,
            ],
        );
    }

    public function markFailure(int $id, string $message): void
    {
        $this->connection->execute(
            'UPDATE backups SET status = :status, error_message = :error_message WHERE id = :id',
            [
                'status' => BackupStatus::FAILED,
                'error_message' => $message,
                'id' => $id,
            ],
        );
    }

    public function delete(int $id): void
    {
        $this->connection->execute(
            'DELETE FROM backups WHERE id = :id',
            ['id' => $id],
        );
    }

    public function find(int $id): ?Backup
    {
        $row = $this->connection->fetchOne(
            'SELECT id, target, storage_key, size_bytes, status, triggered_by, error_message, created_at '
            . 'FROM backups WHERE id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * Most-recent successful backups for a target, newest first. Used by
     * retention pruning and the scheduled-run "is it time?" check.
     *
     * @return list<Backup>
     */
    public function recentSuccessful(string $target, int $limit = 50): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, target, storage_key, size_bytes, status, triggered_by, error_message, created_at '
            . 'FROM backups WHERE target = :target AND status = :status '
            . 'ORDER BY created_at DESC, id DESC LIMIT :limit',
            [
                'target' => $target,
                'status' => BackupStatus::SUCCESS,
                'limit' => $limit,
            ],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /**
     * Most recent run of any status for the given trigger source. The
     * scheduled-run check uses this to decide if a new run is due.
     */
    public function lastForTrigger(string $triggeredBy): ?Backup
    {
        $row = $this->connection->fetchOne(
            'SELECT id, target, storage_key, size_bytes, status, triggered_by, error_message, created_at '
            . 'FROM backups WHERE triggered_by = :triggered_by '
            . 'ORDER BY created_at DESC, id DESC LIMIT 1',
            ['triggered_by' => $triggeredBy],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Full history list for the admin UI, newest first.
     *
     * @return list<Backup>
     */
    public function listAll(int $limit = 100): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, target, storage_key, size_bytes, status, triggered_by, error_message, created_at '
            . 'FROM backups ORDER BY created_at DESC, id DESC LIMIT :limit',
            ['limit' => $limit],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Backup
    {
        return new Backup(
            id: (int) $row['id'],
            target: (string) $row['target'],
            storageKey: (string) $row['storage_key'],
            sizeBytes: (int) $row['size_bytes'],
            status: (string) $row['status'],
            triggeredBy: (string) $row['triggered_by'],
            errorMessage: $row['error_message'] === null ? null : (string) $row['error_message'],
            createdAt: (string) $row['created_at'],
            context: ['db_driver' => $this->connection->driver(), 'media_root' => '', 'app_version' => ''],
        );
    }
}
