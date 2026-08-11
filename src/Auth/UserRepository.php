<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Database\Connection;

/**
 * Reads and writes user records for the Phase 1 schema.
 */
final class UserRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, name, password_hash, is_active, is_admin FROM users WHERE email = :email',
            ['email' => self::normalizeEmail($email)],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function findById(int $id): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, name, password_hash, is_active, is_admin FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Creates a user with a bcrypt-hashed password and returns the stored record.
     */
    public function create(
        string $email,
        string $name,
        string $plaintextPassword,
        bool $isAdmin = false,
        bool $isActive = true,
    ): User {
        $now = self::now();

        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, is_active, is_admin, created_at, updated_at)
             VALUES (:email, :name, :password_hash, :is_active, :is_admin, :created_at, :updated_at)',
            [
                'email' => self::normalizeEmail($email),
                'name' => $name,
                'password_hash' => $this->hasher->hash($plaintextPassword),
                'is_active' => $isActive ? 1 : 0,
                'is_admin' => $isAdmin ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $user = $this->findByEmail($email);
        if ($user === null) {
            throw new \Stead\Exception\SafeException('User could not be read back after creation.');
        }

        return $user;
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->connection->execute(
            'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id',
            ['hash' => $hash, 'updated_at' => self::now(), 'id' => $userId],
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
