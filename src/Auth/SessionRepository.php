<?php

declare(strict_types=1);

namespace Pressless\Auth;

use Pressless\Database\Connection;

/**
 * Owns all SQL for the `sessions` table.
 *
 * `sessions.user_id` is NOT NULL in the Phase 1 schema, so only authenticated
 * sessions have a lifecycle record here. Anonymous pre-login sessions are not
 * persisted; nothing about them needs central expiry or revocation, and login
 * regenerates the identifier before the first record is written.
 */
final class SessionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Inserts or refreshes the lifecycle record for an authenticated session.
     */
    public function persist(
        string $sessionId,
        int $userId,
        string $payload,
        int $lifetimeSeconds,
        string $ipAddress = '',
        string $userAgent = '',
    ): void {
        $now = time();

        $this->connection->execute(
            $this->upsertSql(),
            [
                'id' => $sessionId,
                'user_id' => $userId,
                'ip_address' => substr($ipAddress, 0, 45),
                'user_agent' => substr($userAgent, 0, 255),
                'payload' => $payload,
                'last_activity' => self::formatTimestamp($now),
                'expires_at' => self::formatTimestamp($now + $lifetimeSeconds),
            ],
        );
    }

    /**
     * Returns the session row only when it exists and has not expired.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(string $sessionId): ?array
    {
        $row = $this->connection->fetchOne(
            'SELECT id, user_id, payload, last_activity, expires_at FROM sessions WHERE id = :id',
            ['id' => $sessionId],
        );

        if ($row === null) {
            return null;
        }

        if (self::isExpired((string) ($row['expires_at'] ?? ''))) {
            return null;
        }

        return $row;
    }

    public function touch(string $sessionId, int $lifetimeSeconds): void
    {
        $now = time();

        $this->connection->execute(
            'UPDATE sessions SET last_activity = :last_activity, expires_at = :expires_at WHERE id = :id',
            [
                'last_activity' => self::formatTimestamp($now),
                'expires_at' => self::formatTimestamp($now + $lifetimeSeconds),
                'id' => $sessionId,
            ],
        );
    }

    public function delete(string $sessionId): void
    {
        $this->connection->execute('DELETE FROM sessions WHERE id = :id', ['id' => $sessionId]);
    }

    /**
     * Revokes every session belonging to a user, e.g. after a password change.
     */
    public function deleteForUser(int $userId): void
    {
        $this->connection->execute('DELETE FROM sessions WHERE user_id = :user_id', ['user_id' => $userId]);
    }

    /**
     * Removes expired records. Returns the number of rows deleted.
     */
    public function deleteExpired(): int
    {
        $statement = $this->connection->execute(
            'DELETE FROM sessions WHERE expires_at <= :now',
            ['now' => self::formatTimestamp(time())],
        );

        return $statement->rowCount();
    }

    private function upsertSql(): string
    {
        if ($this->connection->driver() === 'mysql') {
            return 'INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity, expires_at)
                    VALUES (:id, :user_id, :ip_address, :user_agent, :payload, :last_activity, :expires_at)
                    ON DUPLICATE KEY UPDATE
                        user_id = VALUES(user_id),
                        ip_address = VALUES(ip_address),
                        user_agent = VALUES(user_agent),
                        payload = VALUES(payload),
                        last_activity = VALUES(last_activity),
                        expires_at = VALUES(expires_at)';
        }

        return 'INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity, expires_at)
                VALUES (:id, :user_id, :ip_address, :user_agent, :payload, :last_activity, :expires_at)
                ON CONFLICT(id) DO UPDATE SET
                    user_id = excluded.user_id,
                    ip_address = excluded.ip_address,
                    user_agent = excluded.user_agent,
                    payload = excluded.payload,
                    last_activity = excluded.last_activity,
                    expires_at = excluded.expires_at';
    }

    public static function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function isExpired(string $expiresAt): bool
    {
        if ($expiresAt === '') {
            return true;
        }

        $expiry = strtotime($expiresAt . ' UTC');

        return $expiry === false || $expiry <= time();
    }
}
