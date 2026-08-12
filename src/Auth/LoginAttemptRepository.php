<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Database\Connection;

/**
 * Owns all SQL for the `login_attempts` table.
 *
 * Records every login attempt (success or failure) keyed by the submitted
 * email and the requesting IP address. Successful records clear the email's
 * prior failures so a legitimate user isn't penalized by earlier typos; IP
 * failures are never cleared by a success since a shared network path can
 * carry both legitimate and abusive traffic.
 *
 * Pruning happens inline on write: rows older than `2 * window_seconds` are
 * dropped in the same call, bounding table growth without a scheduled job.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Records an attempt and prunes rows older than `$windowSeconds * 2`
     * for the same email and IP. Successful attempts also clear any prior
     * failures recorded for the email via a `cleared_at` marker so IP-side
     * counts remain accurate on shared network paths.
     */
    public function record(string $email, string $ipAddress, bool $succeeded, int $windowSeconds): void
    {
        $email = self::normalizeEmail($email);
        $ipAddress = substr($ipAddress, 0, 45);
        $now = time();
        $cutoff = $now - ($windowSeconds * 2);

        if ($succeeded) {
            $this->connection->execute(
                'UPDATE login_attempts SET cleared_at = :now WHERE email = :email AND cleared_at IS NULL',
                [
                    'now' => self::formatTimestamp($now),
                    'email' => $email,
                ],
            );
        }

        $this->connection->execute(
            'INSERT INTO login_attempts (email, ip_address, succeeded, cleared_at, created_at)
             VALUES (:email, :ip_address, :succeeded, NULL, :created_at)',
            [
                'email' => $email,
                'ip_address' => $ipAddress,
                'succeeded' => $succeeded ? 1 : 0,
                'created_at' => self::formatTimestamp($now),
            ],
        );

        $this->pruneOlderThan($cutoff);
    }

    /**
     * Counts the recorded failed attempts for an email within the window.
     * Rows that have been cleared by a successful login are excluded.
     */
    public function countRecentEmailFailures(string $email, int $windowSeconds): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM login_attempts
              WHERE email = :email
                AND succeeded = 0
                AND cleared_at IS NULL
                AND created_at >= :since',
            [
                'email' => self::normalizeEmail($email),
                'since' => self::formatTimestamp(time() - $windowSeconds),
            ],
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Counts the recorded failed attempts for an IP within the window.
     * IP failures are intentionally NOT cleared by successful logins, so
     * shared-network abuse continues to count until the window ages it out.
     */
    public function countRecentIpFailures(string $ipAddress, int $windowSeconds): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM login_attempts
              WHERE ip_address = :ip
                AND succeeded = 0
                AND created_at >= :since',
            [
                'ip' => substr($ipAddress, 0, 45),
                'since' => self::formatTimestamp(time() - $windowSeconds),
            ],
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Marks an email's recorded failures as cleared so subsequent email-side
     * counts ignore them. IP-side counts are not affected.
     */
    public function clearForEmail(string $email): void
    {
        $this->connection->execute(
            'UPDATE login_attempts SET cleared_at = :now WHERE email = :email AND cleared_at IS NULL',
            [
                'now' => self::formatTimestamp(time()),
                'email' => self::normalizeEmail($email),
            ],
        );
    }

    /**
     * Returns the Unix timestamp of the most recent failure for an identifier,
     * or null when there is none. Used to anchor the exponential backoff.
     */
    public function latestFailureAt(string $column, string $value, int $windowSeconds): ?int
    {
        if ($column !== 'email' && $column !== 'ip_address') {
            throw new \InvalidArgumentException('Unknown login_attempts column: ' . $column);
        }

        $row = $this->connection->fetchOne(
            'SELECT created_at FROM login_attempts
              WHERE ' . $column . ' = :value
                AND succeeded = 0
                AND created_at >= :since
              ORDER BY created_at DESC
              LIMIT 1',
            [
                'value' => $column === 'email' ? self::normalizeEmail($value) : substr($value, 0, 45),
                'since' => self::formatTimestamp(time() - $windowSeconds),
            ],
        );

        if ($row === null) {
            return null;
        }

        $stamp = strtotime((string) ($row['created_at'] ?? '') . ' UTC');

        return $stamp === false ? null : $stamp;
    }

    /**
     * Returns the most recent successful logins across all users, newest
     * first, capped at `$limit`. Each row carries `id`, `email`,
     * `ip_address`, and `created_at`. Used by the admin dashboard's
     * recent-activity feed.
     *
     * @return list<array<string, mixed>>
     */
    public function listRecentSuccesses(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT id, email, ip_address, created_at
               FROM login_attempts
              WHERE succeeded = 1
              ORDER BY created_at DESC, id DESC
              LIMIT :limit',
            ['limit' => $limit],
        );

        return $rows;
    }

    private function pruneOlderThan(int $cutoff): void
    {
        $this->connection->execute(
            'DELETE FROM login_attempts WHERE created_at < :cutoff',
            ['cutoff' => self::formatTimestamp($cutoff)],
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
