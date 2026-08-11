<?php

declare(strict_types=1);

namespace Pressless\Console;

use Pressless\Auth\PasswordHasher;
use Pressless\Auth\User;
use Pressless\Auth\UserRepository;
use Pressless\Config\Configuration;
use Pressless\Database\Connection;
use Pressless\Exception\SafeException;

/**
 * Idempotent sample seeding for evaluator environments.
 *
 * The seeder is safe to re-run: existing administrators and collections are
 * preserved, only missing records are created. The temporary administrator
 * password is reported to the caller so the serve command can show it without
 * committing credentials anywhere.
 */
final class Seeder
{
    /** Default administrator created on first seed. */
    private const ADMIN_EMAIL = 'admin@example.com';
    private const ADMIN_NAME = 'Site Administrator';
    private const ADMIN_PASSWORD_LENGTH = 16;

    /** Sample collections created on first seed. */
    private const SAMPLE_COLLECTIONS = [
        ['slug' => 'pages', 'name' => 'Pages'],
        ['slug' => 'posts', 'name' => 'Posts'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    /**
     * Runs the seed and reports what was created so the caller can surface the
     * temporary administrator credentials to the user.
     *
     * Pass `allowProduction = true` from an explicit override (e.g.
     * `bin/serve --allow-production-seed`) to seed in a production environment.
     *
     * @return array{admin_email: ?string, admin_password: ?string, collections_created: int}
     */
    public function seed(bool $allowProduction = false): array
    {
        if (!$this->config->isDevelopment() && !$allowProduction && !$this->config->getBool('app.allow_seed', false)) {
            throw new SafeException(
                'Seeding is only allowed in development environments.',
                ['environment' => $this->config->environment()],
            );
        }

        $hasher = new PasswordHasher();
        $users = new UserRepository($this->connection, $hasher);

        $existing = $this->findUser(self::ADMIN_EMAIL);
        if ($existing instanceof User) {
            $adminEmail = null;
            $adminPassword = null;
        } else {
            $tempPassword = self::generatePassword();
            $users->create(self::ADMIN_EMAIL, self::ADMIN_NAME, $tempPassword, true, true);
            $adminEmail = self::ADMIN_EMAIL;
            $adminPassword = $tempPassword;
        }

        $created = 0;
        foreach (self::SAMPLE_COLLECTIONS as $collection) {
            if ($this->collectionExists($collection['slug'])) {
                continue;
            }
            $this->createCollection($collection['slug'], $collection['name']);
            $created++;
        }

        return [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'collections_created' => $created,
        ];
    }

    private function findUser(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, name, password_hash, is_active, is_admin FROM users WHERE email = :email',
            ['email' => UserRepository::normalizeEmail($email)],
        );

        return $row === null ? null : User::fromRow($row);
    }

    private function collectionExists(string $slug): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => $slug],
        );
        return $row !== null;
    }

    private function createCollection(string $slug, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $schema = json_encode([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'required' => true],
                ['key' => 'body', 'type' => 'markdown'],
            ],
        ]);

        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema_definition, :created_at, :updated_at)',
            [
                'slug' => $slug,
                'name' => $name,
                'schema_definition' => $schema,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private static function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $length = self::ADMIN_PASSWORD_LENGTH;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }
}