<?php

declare(strict_types=1);

namespace Stead\Tests\Integration\Backups;

use PHPUnit\Framework\TestCase;
use Stead\Backups\BackupRepository;
use Stead\Backups\BackupRunner;
use Stead\Backups\BackupStatus;
use Stead\Backups\BackupTrigger;
use Stead\Backups\Dump\DumperFactory;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;

final class BackupRetentionSmokeTest extends TestCase
{
    private string $tmp;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/stead-backup-retention-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/var/log', 0775, true);
        mkdir($this->tmp . '/var/backups', 0775, true);
        mkdir($this->tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->tmp . '/database/migrations/' . basename($src));
        }
        $this->mediaDir = $this->tmp . '/storage/media';
        mkdir($this->mediaDir, 0775, true);

        touch($this->tmp . '/stead.sqlite');

        $this->config = new Configuration(
            $this->tmp,
            'development',
            [
                'database' => [
                    'connection' => 'sqlite',
                    'database' => 'stead.sqlite',
                    'host' => '',
                    'port' => 0,
                    'username' => '',
                    'password' => '',
                    'charset' => 'utf8mb4',
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                    'storage' => 'storage/media',
                ],
                'sessions' => ['name' => 'stead_session'],
                'backups' => [
                    'target' => 'local',
                    'local_path' => 'var/backups',
                    'retention_count' => 2,
                    'frequency_hours' => 24,
                ],
            ],
        );
        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->rrm($this->tmp);
    }

    /**
     * Smoke test: exceed retention count → confirm oldest backups pruned.
     */
    public function testRetentionPrunesOldestBeyondCount(): void
    {
        $this->migrator->migrate();
        $runner = $this->buildRunner();
        $repo = $this->backups();

        $first = $runner->run(BackupTrigger::MANUAL);
        $this->tickClock();
        $second = $runner->run(BackupTrigger::MANUAL);
        $this->tickClock();
        $third = $runner->run(BackupTrigger::MANUAL);
        $this->tickClock();
        $fourth = $runner->run(BackupTrigger::MANUAL);

        self::assertSame(BackupStatus::SUCCESS, $first->status());
        self::assertSame(BackupStatus::SUCCESS, $fourth->status());

        $remaining = $repo->recentSuccessful('local', 50);
        self::assertLessThanOrEqual(2, count($remaining), 'at most 2 backups should remain after retention');
        // The most recent two (third and fourth) should remain.
        $remainingIds = array_map(static fn($b) => $b->id(), $remaining);
        self::assertContains($third->id(), $remainingIds, 'third backup should remain');
        self::assertContains($fourth->id(), $remainingIds, 'fourth backup should remain');
        self::assertNotContains($first->id(), $remainingIds, 'first backup should be pruned');
        self::assertNotContains($second->id(), $remainingIds, 'second backup should be pruned');
    }

    private function tickClock(): void
    {
        // created_at has second precision; wait long enough that each
        // backup lands on a distinct second. 1.1s of sleep keeps the
        // test under 5 seconds total.
        usleep(1_100_000);
    }

    private function buildRunner(): BackupRunner
    {
        return new BackupRunner(
            $this->config,
            $this->backups(),
            $this->storageTargets(),
            new DumperFactory($this->connection, $this->config),
            $this->mediaDir,
            new \Psr\Log\NullLogger(),
        );
    }

    private function backups(): BackupRepository
    {
        return new BackupRepository($this->connection);
    }

    private function storageTargets(): StorageTargetFactory
    {
        return new StorageTargetFactory($this->config);
    }

    private function rrm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            $base = basename((string) $entry);
            if ($base === '.' || $base === '..') {
                continue;
            }
            if (is_dir($entry)) {
                $this->rrm($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }
}
