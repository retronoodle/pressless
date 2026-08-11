<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pressless\Auth\User;
use Pressless\Config\Configuration;
use Pressless\Console\Seeder;
use Pressless\Database\Connection;
use Pressless\Database\Migrator;
use Pressless\Exception\SafeException;

final class SeederTest extends TestCase
{
    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/pressless-seed-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        copy(
            __DIR__ . '/../../../database/migrations/20260811000001_initial_schema.sqlite.sql',
            $this->projectRoot . '/database/migrations/20260811000001_initial_schema.sqlite.sql',
        );
        $this->dbPath = $this->projectRoot . '/pressless.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'development',
            [
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                ],
            ],
        );

        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
        $this->migrator->migrate();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        @unlink("{$this->projectRoot}/database/migrations/20260811000001_initial_schema.sqlite.sql");
        @rmdir("{$this->projectRoot}/database/migrations");
        @rmdir("{$this->projectRoot}/database");
        @rmdir($this->projectRoot);
    }

    public function testSeedCreatesAdministratorAndSampleCollections(): void
    {
        $report = (new Seeder($this->connection, $this->config))->seed();

        $this->assertSame('admin@example.com', $report['admin_email']);
        $this->assertNotNull($report['admin_password']);
        $this->assertGreaterThanOrEqual(8, strlen((string) $report['admin_password']));
        $this->assertSame(2, $report['collections_created']);

        $user = $this->findUser('admin@example.com');
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->isAdmin);
        $this->assertTrue($user->isActive);

        $this->assertSame(2, $this->collectionCount());
    }

    public function testSeedIsRepeatable(): void
    {
        $seeder = new Seeder($this->connection, $this->config);
        $seeder->seed();
        $second = $seeder->seed();

        $this->assertNull($second['admin_email']);
        $this->assertNull($second['admin_password']);
        $this->assertSame(0, $second['collections_created']);
        $this->assertSame(1, $this->userCount());
        $this->assertSame(2, $this->collectionCount());
    }

    public function testSeedRefusesToRunInProduction(): void
    {
        $config = new Configuration(
            $this->projectRoot,
            'production',
            [
                'app' => ['debug' => false],
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                ],
            ],
        );

        $this->expectException(SafeException::class);
        (new Seeder($this->connection, $config))->seed();
    }

    public function testSeedReportsGeneratedPasswordOnlyOnFirstRun(): void
    {
        $seeder = new Seeder($this->connection, $this->config);
        $first = $seeder->seed();
        $second = $seeder->seed();

        $this->assertNotNull($first['admin_password']);
        $this->assertNull($second['admin_password']);
    }

    private function findUser(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, name, password_hash, is_active, is_admin FROM users WHERE email = :email',
            ['email' => $email],
        );
        return $row === null ? null : User::fromRow($row);
    }

    private function userCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    private function collectionCount(): int
    {
        return (int) ($this->connection->fetchOne('SELECT COUNT(*) AS c FROM collections')['c'] ?? 0);
    }
}