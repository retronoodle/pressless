<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Exception\SafeException;

/**
 * Creates and verifies bcrypt password hashes.
 *
 * Plaintext passwords are only ever passed to PHP's password API; they are
 * never logged, compared directly, or included in exception messages.
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 8;

    /**
     * bcrypt truncates input beyond 72 bytes, so longer submissions are
     * rejected rather than silently accepted on a shortened prefix.
     */
    public const MAX_LENGTH = 72;

    public function __construct(private readonly int $cost = PASSWORD_BCRYPT_DEFAULT_COST)
    {
    }

    public function hash(string $plaintext): string
    {
        $this->assertHashable($plaintext);

        return password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        if ($hash === '' || $plaintext === '') {
            return false;
        }

        return password_verify($plaintext, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }

    private function assertHashable(string $plaintext): void
    {
        $length = strlen($plaintext);

        if ($length < self::MIN_LENGTH) {
            throw new SafeException(
                sprintf('Credential must be at least %d characters.', self::MIN_LENGTH),
            );
        }

        if ($length > self::MAX_LENGTH) {
            throw new SafeException(
                sprintf('Credential must be at most %d bytes.', self::MAX_LENGTH),
            );
        }
    }
}
