<?php

declare(strict_types=1);

namespace Stead\Invites;

/**
 * A pending or historical invite record.
 *
 * `tokenHash` is the only thing stored; the plaintext token lives in the
 * outgoing email and is never recoverable after it has been issued.
 */
final class Invite
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly int $roleId,
        public readonly string $roleName,
        public readonly string $tokenHash,
        public readonly string $expiresAt,
        public readonly ?string $acceptedAt,
        public readonly ?string $revokedAt,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['email'] ?? ''),
            (int) ($row['role_id'] ?? 0),
            (string) ($row['role_name'] ?? ''),
            (string) ($row['token_hash'] ?? ''),
            (string) ($row['expires_at'] ?? ''),
            isset($row['accepted_at']) ? (string) $row['accepted_at'] : null,
            isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
            isset($row['created_by']) ? (int) $row['created_by'] : null,
            (string) ($row['created_at'] ?? ''),
        );
    }

    public function isPending(string $now): bool
    {
        if ($this->acceptedAt !== null) {
            return false;
        }
        if ($this->revokedAt !== null) {
            return false;
        }
        return $this->expiresAt > $now;
    }
}
