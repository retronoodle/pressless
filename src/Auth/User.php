<?php

declare(strict_types=1);

namespace Stead\Auth;

/**
 * A user record loaded from the `users` table.
 *
 * The password hash is held so the authentication service can verify and
 * transparently rehash it; it is never exposed through serialization.
 * Authorization data (role, role name) is carried alongside identity so
 * route handlers can answer "is this user allowed?" without an extra join.
 */
final class User implements \JsonSerializable
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_AUTHOR = 'author';

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly bool $isActive,
        public readonly int $roleId,
        public readonly string $roleName,
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
            (int) ($row['role_id'] ?? 0),
            (string) ($row['role_name'] ?? ''),
        );
    }

    public function isAdmin(): bool
    {
        return $this->roleName === self::ROLE_ADMIN;
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
            'role_id' => $this->roleId,
            'role_name' => $this->roleName,
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
