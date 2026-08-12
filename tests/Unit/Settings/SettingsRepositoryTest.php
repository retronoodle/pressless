<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Stead\Settings\Settings;
use Stead\Settings\SettingsRepository;
use Stead\Database\Connection;
use Stead\Config\Configuration;
use Stead\Database\Migrator;

/**
 * Round-trips the single-row Settings through its repository against a
 * real SQLite schema. Covers the seed-defaults-on-missing-row behaviour
 * and the upsert-on-save contract.
 */
final class SettingsRepositoryTest extends TestCase
{
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;
    private SettingsRepository $repository;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-settings-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
        mkdir($tmp . '/config', 0775, true);
        mkdir($tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $tmp . '/database/migrations/' . basename($src));
        }
        $this->config = new Configuration(
            $tmp,
            'development',
            [
                'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
                'sessions' => ['name' => 'stead_settings'],
            ],
        );
        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
        $this->migrator->migrate();
        $this->repository = new SettingsRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testLoadAfterMigrationReturnsSeededRow(): void
    {
        $settings = $this->repository->load();

        $this->assertSame('', $settings->siteName);
        $this->assertSame('UTC', $settings->timezone);
        $this->assertSame('Y-m-d', $settings->dateFormat);
    }

    public function testSaveThenLoadRoundTripsValues(): void
    {
        $this->repository->save(new Settings('My Site', 'Europe/London', 'F j, Y'));

        $settings = $this->repository->load();
        $this->assertSame('My Site', $settings->siteName);
        $this->assertSame('Europe/London', $settings->timezone);
        $this->assertSame('F j, Y', $settings->dateFormat);
    }

    public function testSaveIsAnUpsertInPlace(): void
    {
        $this->repository->save(new Settings('First', 'UTC', 'Y-m-d'));
        $this->repository->save(new Settings('Second', 'America/New_York', 'd/m/Y'));

        $rows = $this->connection->fetchAll('SELECT id FROM settings');
        $this->assertCount(1, $rows, 'saving settings must not create duplicate rows.');

        $settings = $this->repository->load();
        $this->assertSame('Second', $settings->siteName);
        $this->assertSame('America/New_York', $settings->timezone);
        $this->assertSame('d/m/Y', $settings->dateFormat);
    }
}