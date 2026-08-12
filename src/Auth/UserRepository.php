<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Database\Connection;

/**
 * Reads and writes user records, joining the assigned role's id and name so
 * authorization checks have what they need without a second round-trip.
 */
final class UserRepository
{
    private const SELECT_USER = <<<'SQL'
        SELECT u.id, u.email, u.name, u.password_hash, u.is_active, u.role_id,
               r.name AS role_name
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
    SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            self::SELECT_USER . ' WHERE u.email = :email',
            ['email' => self::normalizeEmail($email)],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function findById(int $id): ?User
    {
        $row = $this->connection->fetchOne(
            self::SELECT_USER . ' WHERE u.id = :id',
            ['id' => $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT_USER . ' ORDER BY u.email ASC',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = User::fromRow($row);
        }
        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function listRoles(): array
    {
        $rows = $this->connection->fetchAll('SELECT id, name FROM roles ORDER BY id ASC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Creates a user with a bcrypt-hashed password and returns the stored record.
     *
     * @param string $roleName one of the seeded role names (admin/editor/author)
     */
    public function create(
        string $email,
        string $name,
        string $plaintextPassword,
        string $roleName = User::ROLE_EDITOR,
        bool $isActive = true,
    ): User {
        $now = self::now();
        $roleId = $this->roleIdFor($roleName);

        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, is_active, role_id, created_at, updated_at)
             VALUES (:email, :name, :password_hash, :is_active, :role_id, :created_at, :updated_at)',
            [
                'email' => self::normalizeEmail($email),
                'name' => $name,
                'password_hash' => $this->hasher->hash($plaintextPassword),
                'is_active' => $isActive ? 1 : 0,
                'role_id' => $roleId,
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

    public function updateRole(int $userId, string $roleName): void
    {
        $this->connection->execute(
            'UPDATE users SET role_id = :role_id, updated_at = :updated_at WHERE id = :id',
            [
                'role_id' => $this->roleIdFor($roleName),
                'updated_at' => self::now(),
                'id' => $userId,
            ],
        );
    }

    public function updateIsActive(int $userId, bool $isActive): void
    {
        $this->connection->execute(
            'UPDATE users SET is_active = :is_active, updated_at = :updated_at WHERE id = :id',
            [
                'is_active' => $isActive ? 1 : 0,
                'updated_at' => self::now(),
                'id' => $userId,
            ],
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function roleIdFor(string $roleName): int
    {
        $row = $this->connection->fetchOne(
            'SELECT id FROM roles WHERE name = :name',
            ['name' => $roleName],
        );
        if ($row === null) {
            throw new \Stead\Exception\SafeException(
                sprintf('Unknown role "%s".', $roleName),
                ['role' => $roleName],
            );
        }
        return (int) ($row['id'] ?? 0);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
