<?php

declare(strict_types=1);

namespace Pressless\Auth;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * A native session handler backed by the `sessions` table.
 *
 * Only authenticated sessions are persisted: the handler inspects the encoded
 * payload for a user id and skips the write when there is none, because
 * `sessions.user_id` is NOT NULL. Reads treat missing, expired, or revoked
 * records as an empty session so a stale cookie can never resume state.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly int $lifetimeSeconds,
        private string $ipAddress = '',
        private string $userAgent = '',
    ) {
    }

    public function withRequestContext(string $ipAddress, string $userAgent): self
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        return $this;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $row = $this->sessions->findActive($id);
        if ($row === null) {
            return '';
        }

        return (string) ($row['payload'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        $userId = self::extractUserId($data);
        if ($userId === null) {
            // Anonymous session: nothing to track centrally.
            return true;
        }

        $this->sessions->persist(
            $id,
            $userId,
            $data,
            $this->lifetimeSeconds,
            $this->ipAddress,
            $this->userAgent,
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->sessions->delete($id);
        return true;
    }

    /**
     * @return int<0, max>|false
     */
    public function gc(int $max_lifetime): int|false
    {
        $deleted = $this->sessions->deleteExpired();
        return max(0, $deleted);
    }

    public function validateId(string $id): bool
    {
        return $this->sessions->findActive($id) !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->sessions->touch($id, $this->lifetimeSeconds);
        return true;
    }

    /**
     * Reads the user id out of PHP's encoded session payload without
     * unserializing untrusted data into the current scope.
     *
     * The pattern matches the standard `php` serialize handler, which writes
     * each entry as `key|serialized_value`. Other serializers (e.g. `php_serialize`,
     * `php_binary`) would not produce this layout, so a swap of the session
     * serializer must be paired with a matching change here.
     */
    public static function extractUserId(string $payload): ?int
    {
        if ($payload === '') {
            return null;
        }

        if (preg_match('/' . preg_quote(SessionStore::USER_KEY, '/') . '\|i:(\d+);/', $payload, $matches) === 1) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }
}
