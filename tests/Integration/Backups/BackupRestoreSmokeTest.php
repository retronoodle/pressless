<?php

declare(strict_types=1);

namespace Stead\Tests\Integration\Backups;

use PHPUnit\Framework\TestCase;
use Stead\Backups\BackupRepository;
use Stead\Backups\BackupRunner;
use Stead\Backups\BackupStatus;
use Stead\Backups\BackupTrigger;
use Stead\Backups\Dump\DumperFactory;
use Stead\Backups\Restore\RestoreRunner;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;

final class BackupRestoreSmokeTest extends TestCase
{
    private string $tmp;
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;
    private string $backupDir;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/stead-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/var/log', 0775, true);
        mkdir($this->tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->tmp . '/database/migrations/' . basename($src));
        }
        $this->backupDir = $this->tmp . '/var/backups';
        mkdir($this->backupDir, 0775, true);
        $this->mediaDir = $this->tmp . '/storage/media';
        mkdir($this->mediaDir . '/5', 0775, true);
        file_put_contents($this->mediaDir . '/5/photo.txt', 'photo-data');

        $dbPath = $this->tmp . '/stead.sqlite';
        touch($dbPath);

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
     * Smoke test: create backup → wipe data → restore → site state matches.
     */
    public function testCreateBackupWipeDataRestoreRecoversSite(): void
    {
        $this->migrator->migrate();
        $runner = $this->buildRunner();

        // Seed users, then take a backup.
        $this->connection->execute(
            'INSERT INTO users (name, email, password_hash, is_active, created_at, updated_at) '
            . "VALUES ('alice', 'alice@example.com', 'hash', 1, '2026-01-01', '2026-01-01')",
        );
        $this->connection->execute(
            'INSERT INTO users (name, email, password_hash, is_active, created_at, updated_at) '
            . "VALUES ('bob', 'bob@example.com', 'hash2', 1, '2026-01-01', '2026-01-01')",
        );

        $backup = $runner->run(BackupTrigger::MANUAL);
        self::assertSame(BackupStatus::SUCCESS, $backup->status(), 'backup should succeed');
        self::assertGreaterThan(0, $backup->sizeBytes(), 'backup should have a non-zero size');

        // Capture backup id for later lookup. After we wipe the rows,
        // the schema is still intact so we can still query `backups`.
        $backupId = $backup->id();

        // Wipe the users table.
        $this->connection->execute('DELETE FROM users');
        $row = $this->connection->fetchOne('SELECT count(*) AS c FROM users');
        self::assertSame(0, (int) ($row['c'] ?? 0), 'users table should be empty after wipe');

        // Restore from the backup.
        $restored = $this->backups()->find($backupId);
        self::assertNotNull($restored, 'backup row should still be queryable');

        $restore = new RestoreRunner(
            $this->config,
            $this->connection,
            $this->backups(),
            $this->storageTargets(),
            $this->mediaDir,
        );
        $restore->restore($restored);

        $users = $this->connection->fetchAll('SELECT name, email FROM users ORDER BY id ASC');
        self::assertCount(2, $users, 'restore should bring both users back');
        self::assertSame('alice', $users[0]['name']);
        self::assertSame('bob', $users[1]['name']);
    }

    /**
     * Smoke test: media files in the archive are extracted back to the
     * media root during restore.
     */
    public function testRestoreReplacesMediaDirectoryContents(): void
    {
        $this->migrator->migrate();
        $runner = $this->buildRunner();

        $backup = $runner->run(BackupTrigger::MANUAL);
        $backupId = $backup->id();

        // Simulate "data on disk changed after backup was taken" — the
        // restore should wipe the current media root before extracting.
        file_put_contents($this->mediaDir . '/stale.txt', 'old-data');

        $restored = $this->backups()->find($backupId);
        self::assertNotNull($restored);

        $restore = new RestoreRunner(
            $this->config,
            $this->connection,
            $this->backups(),
            $this->storageTargets(),
            $this->mediaDir,
        );
        $restore->restore($restored);

        // The archive contained media/5/photo.txt; that file should now exist.
        self::assertFileExists($this->mediaDir . '/5/photo.txt');
        self::assertSame('photo-data', file_get_contents($this->mediaDir . '/5/photo.txt'));

        // The pre-existing stale file should have been wiped by the restore.
        self::assertFileDoesNotExist($this->mediaDir . '/stale.txt');
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
