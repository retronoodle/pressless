<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Console\ServePreflight;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Exception\SafeException;

final class ServePreflightTest extends TestCase
{
    private string $projectRoot;
    private string $dbPath;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-serve-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->dbPath = $this->projectRoot . '/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'development',
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

        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/database",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testNormalStartupAppliesPendingMigrations(): void
    {
        $result = $this->preflight()->run(
            fresh: false,
            seed: false,
            server: ['host' => '127.0.0.1', 'port' => 8000],
        );

        $this->assertGreaterThanOrEqual(4, count($result['migrations']['applied']));
        $this->assertNull($result['seed']);
    }

    public function testFreshResetDropsExistingRecordsBeforeMigrating(): void
    {
        $this->migrator->migrate();
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['leftover@example.com', 'Leftover', 'hash', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')],
        );

        $result = $this->preflight()->run(
            fresh: true,
            seed: false,
            server: ['host' => '127.0.0.1', 'port' => 8000],
        );

        $this->assertGreaterThanOrEqual(4, count($result['migrations']['applied']));
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM users');
        $this->assertSame(0, (int) $row['c']);
    }

    public function testSeedIsRepeatable(): void
    {
        $this->preflight()->run(false, true, ['host' => '127.0.0.1', 'port' => 8000]);
        $second = $this->preflight()->run(false, true, ['host' => '127.0.0.1', 'port' => 8000]);

        $this->assertSame(1, $this->userCount(), 'Administrator must not be duplicated.');
        $this->assertSame(2, $this->collectionCount(), 'Sample collections must not be duplicated.');
        $this->assertNull($second['seed']['admin_email']);
        $this->assertSame(0, $second['seed']['collections_created']);
    }

    public function testWithoutFreshExistingRecordsArePreserved(): void
    {
        $this->migrator->migrate();
        $this->connection->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['keep@example.com', 'Keep', 'hash', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')],
        );

        $this->preflight()->run(false, false, ['host' => '127.0.0.1', 'port' => 8000]);

        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM users');
        $this->assertSame(1, (int) $row['c'], 'Without --fresh the user must remain.');
    }

    public function testSeedInProductionIsRefusedWithoutOverride(): void
    {
        $prodConfig = new Configuration(
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
        $this->migrator->migrate();

        try {
            $this->preflight($prodConfig)->run(false, true, ['host' => '127.0.0.1', 'port' => 8000]);
            $this->fail('Expected SafeException for production seed.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('Refusing to seed', $e->getMessage());
        }

        $this->assertSame(0, $this->userCount(), 'No user should have been created.');
    }

    public function testSeedInProductionWithOverrideProceeds(): void
    {
        $prodConfig = new Configuration(
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
        $this->migrator->migrate();

        $result = $this->preflight($prodConfig)->run(
            fresh: false,
            seed: true,
            server: ['host' => '127.0.0.1', 'port' => 8000],
            allowProductionSeed: true,
        );

        $this->assertSame(1, $this->userCount());
        $this->assertSame(2, $this->collectionCount());
        $this->assertSame('admin@example.com', $result['seed']['admin_email']);
    }

    public function testInvalidPortFails(): void
    {
        $this->expectException(SafeException::class);
        $this->preflight()->run(false, false, ['host' => '127.0.0.1', 'port' => 0]);
    }

    public function testDatabaseConnectionFailureSurfaces(): void
    {
        // Point the connection at a directory so the open fails.
        $badConfig = new Configuration(
            $this->projectRoot,
            'development',
            [
                'app' => ['debug' => false],
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->projectRoot, // a directory, not a file
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                ],
            ],
        );

        $badConnection = new Connection($badConfig);
        $preflight = new ServePreflight($badConnection, $badConfig);

        $this->expectException(SafeException::class);
        $preflight->run(false, false, ['host' => '127.0.0.1', 'port' => 8000]);
    }

    private function preflight(?Configuration $config = null): ServePreflight
    {
        return new ServePreflight($this->connection, $config ?? $this->config);
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