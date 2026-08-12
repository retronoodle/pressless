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

final class PreUpdateBackupSmokeTest extends TestCase
{
    private string $tmp;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/stead-pre-update-' . bin2hex(random_bytes(4));
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
                    'retention_count' => 7,
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
     * Smoke test: a pre-update backup runs before instructions would
     * be displayed. We exercise the runner directly since the
     * controller is a thin wrapper that adds the "available update?"
     * check.
     */
    public function testPreUpdateBackupRunsBeforeInstructions(): void
    {
        $this->migrator->migrate();

        $runner = new BackupRunner(
            $this->config,
            new BackupRepository($this->connection),
            new StorageTargetFactory($this->config),
            new DumperFactory($this->connection, $this->config),
            $this->mediaDir,
            new \Psr\Log\NullLogger(),
        );

        $backup = $runner->run(BackupTrigger::PRE_UPDATE);

        self::assertSame(BackupStatus::SUCCESS, $backup->status(), 'pre-update backup should succeed');
        self::assertSame(BackupTrigger::PRE_UPDATE, $backup->triggeredBy());

        $repo = new BackupRepository($this->connection);
        $lastPreUpdate = $repo->lastForTrigger(BackupTrigger::PRE_UPDATE);
        self::assertNotNull($lastPreUpdate, 'pre_update backup row should exist');
        self::assertSame($backup->id(), $lastPreUpdate->id());
        self::assertSame(BackupStatus::SUCCESS, $lastPreUpdate->status());
    }

    /**
     * If the configured target fails (e.g. unwritable local path),
     * the runner must mark the backup as failed and not produce a row
     * that the admin UI would consider restorable.
     */
    public function testFailedPreUpdateBackupIsRecordedAsFailed(): void
    {
        $this->migrator->migrate();

        // Make the local backup dir un-writable by pointing it at a
        // path the runner cannot create.
        $badConfig = new Configuration(
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
                    // This directory is a regular file — directory creation will fail.
                    'local_path' => 'var/backups/this-is-a-file-not-a-dir',
                    'retention_count' => 7,
                    'frequency_hours' => 24,
                ],
            ],
        );
        // Make `var/backups/this-is-a-file-not-a-dir` an actual file.
        if (!is_dir($this->tmp . '/var/backups')) {
            mkdir($this->tmp . '/var/backups', 0775, true);
        }
        file_put_contents($this->tmp . '/var/backups/this-is-a-file-not-a-dir', 'x');

        $runner = new BackupRunner(
            $badConfig,
            new BackupRepository($this->connection),
            new StorageTargetFactory($badConfig),
            new DumperFactory($this->connection, $badConfig),
            $this->mediaDir,
            new \Psr\Log\NullLogger(),
        );

        try {
            $runner->run(BackupTrigger::PRE_UPDATE);
            self::fail('Expected backup to fail.');
        } catch (\Throwable $e) {
            self::assertNotNull($e->getMessage());
        }

        // The pre-update backup row should still exist but be failed.
        $repo = new BackupRepository($this->connection);
        $lastPreUpdate = $repo->lastForTrigger(BackupTrigger::PRE_UPDATE);
        self::assertNotNull($lastPreUpdate, 'pre_update backup row should be recorded');
        self::assertSame(BackupStatus::FAILED, $lastPreUpdate->status());
        self::assertNotEmpty($lastPreUpdate->errorMessage());
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
