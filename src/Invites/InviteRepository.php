<?php

declare(strict_types=1);

namespace Stead\Invites;

use Stead\Database\Connection;

/**
 * Reads and writes invite records. Tokens are issued as 32-byte random
 * values, base64url-encoded, and only their hash is persisted — so a leaked
 * database row cannot be replayed.
 *
 * Re-inviting the same email invalidates any earlier pending token for that
 * address; the prior row's `revoked_at` is set so the audit trail is
 * preserved.
 */
final class InviteRepository
{
    public const TTL_SECONDS = 7 * 24 * 60 * 60;

    private const SELECT_INVITE = <<<'SQL'
        SELECT i.id, i.email, i.role_id, i.token_hash, i.expires_at,
               i.accepted_at, i.revoked_at, i.created_by, i.created_at,
               r.name AS role_name
          FROM invites i
          LEFT JOIN roles r ON r.id = i.role_id
    SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Creates a new pending invite, revoking any earlier pending invite for the
     * same address first. Returns the issued plaintext token along with the
     * persisted record.
     *
     * @return array{invite: Invite, token: string}
     */
    public function create(string $email, int $roleId, ?int $createdBy): array
    {
        $email = self::normalizeEmail($email);
        $this->revokePendingForEmail($email);

        $token = self::generateToken();
        $tokenHash = self::hashToken($token);
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $this->connection->execute(
            'INSERT INTO invites (email, role_id, token_hash, expires_at, accepted_at, revoked_at, created_by, created_at)
             VALUES (:email, :role_id, :token_hash, :expires_at, NULL, NULL, :created_by, :created_at)',
            [
                'email' => $email,
                'role_id' => $roleId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
                'created_at' => $now,
            ],
        );

        $id = $this->lastInsertId();
        $invite = $this->findById($id);
        if ($invite === null) {
            throw new \Stead\Exception\SafeException('Invite could not be read back after creation.');
        }

        return ['invite' => $invite, 'token' => $token];
    }

    /**
     * Finds an invite by its plaintext token, hashing the token before the
     * query. Returns null when no row matches, so the caller cannot
     * distinguish "unknown token" from "expired/used token" beyond the
     * invite's own status fields.
     */
    public function findByToken(string $token): ?Invite
    {
        $tokenHash = self::hashToken($token);
        $row = $this->connection->fetchOne(
            self::SELECT_INVITE . ' WHERE i.token_hash = :token_hash',
            ['token_hash' => $tokenHash],
        );
        return $row === null ? null : Invite::fromRow($row);
    }

    public function findById(int $id): ?Invite
    {
        $row = $this->connection->fetchOne(
            self::SELECT_INVITE . ' WHERE i.id = :id',
            ['id' => $id],
        );
        return $row === null ? null : Invite::fromRow($row);
    }

    public function markAccepted(int $id): void
    {
        $this->connection->execute(
            'UPDATE invites SET accepted_at = :now WHERE id = :id',
            ['now' => gmdate('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function revokePendingForEmail(string $email): void
    {
        $this->connection->execute(
            'UPDATE invites
                SET revoked_at = :now
              WHERE email = :email
                AND accepted_at IS NULL
                AND revoked_at IS NULL
                AND expires_at > :now',
            [
                'now' => gmdate('Y-m-d H:i:s'),
                'email' => $email,
            ],
        );
    }

    private function lastInsertId(): int
    {
        $id = $this->connection->pdo()->lastInsertId();
        return $id === false ? 0 : (int) $id;
    }
}
