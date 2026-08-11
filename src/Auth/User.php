<?php

declare(strict_types=1);

namespace Stead\Auth;

/**
 * A user record loaded from the `users` table.
 *
 * The password hash is held so the authentication service can verify and
 * transparently rehash it; it is never exposed through serialization.
 */
final class User implements \JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly bool $isActive,
        public readonly bool $isAdmin,
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
            (string) ($row['name'] ?? ''),
            (string) ($row['password_hash'] ?? ''),
            (bool) ($row['is_active'] ?? false),
            (bool) ($row['is_admin'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'is_active' => $this->isActive,
            'is_admin' => $this->isAdmin,
        ];
    }

    /**
     * Keeps the hash out of stack traces and debug output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
