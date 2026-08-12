<?php

declare(strict_types=1);

namespace Stead\Backups;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stead\Backups\Archive\ArchiveBuilder;
use Stead\Backups\Dump\DumperFactory;
use Stead\Backups\Storage\StorageTarget;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;

/**
 * Top-level orchestrator: build the archive, upload it, record the row,
 * prune older backups. Returns the resulting {@see Backup}.
 *
 * Split out of the console command so the same flow can be driven from
 * the update-instructions page (pre-update backup), from tests, and
 * from a future scheduled-task invocation path.
 */
final class BackupRunner
{
    public function __construct(
        private readonly Configuration $config,
        private readonly BackupRepository $backups,
        private readonly StorageTargetFactory $storageTargets,
        private readonly DumperFactory $dumpers,
        private readonly string $mediaRoot,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(string $triggeredBy): Backup
    {
        $target = $this->storageTargets->fromConfig();
        $targetName = $target->name();

        $stamp = gmdate('Ymd_His');
        $storageKey = sprintf('stead-backup-%s-%s.zip', $stamp, bin2hex(random_bytes(3)));

        $backup = $this->backups->create($targetName, $storageKey, $triggeredBy);

        $tempDir = $this->tempDir();
        $dumpPath = $tempDir . '/dump.sql';
        $archivePath = $tempDir . '/' . $storageKey;

        try {
            // 1. Mark the row as success-with-zero-size first so the
            //    dump captures it in the "success" state. The actual
            //    size is updated after the archive is built. This
            //    avoids a chicken-and-egg where the dump captures the
            //    row as "pending" (the state during the run) and the
            //    restored backup shows the wrong status.
            $this->backups->markSuccess($backup->id(), 0);

            // 2. Dump DB.
            $dumper = $this->dumpers->pick();
            $dumper->dumpTo($dumpPath);

            // 3. Build archive.
            $archiveBuilder = new ArchiveBuilder($this->mediaRoot);
            $archiveSize = $archiveBuilder->build(
                archivePath: $archivePath,
                dumpPath: $dumpPath,
                dumpNameInArchive: 'dump.sql',
                context: [
                    'db_driver' => $this->dumpers->getDriver(),
                    'app_version' => $this->readAppVersion(),
                ],
            );

            // 4. Upload / write to target.
            $target->put($storageKey, $archivePath);

            $remoteSize = @filesize($archivePath);
            if ($remoteSize === false) {
                $remoteSize = $archiveSize;
            }

            // 5. Update the size now that the archive is built and stored.
            $this->backups->markSuccess($backup->id(), $remoteSize);
            $this->prune($target, $triggeredBy);

            $this->logger->info('Backup created.', [
                'id' => $backup->id(),
                'target' => $targetName,
                'key' => $storageKey,
                'size' => $remoteSize,
            ]);

            return $this->backups->find($backup->id()) ?? $backup;
        } catch (\Throwable $e) {
            $this->backups->markFailure($backup->id(), $e->getMessage());
            $this->logger->error('Backup failed.', [
                'id' => $backup->id(),
                'exception' => $e->getMessage(),
            ]);
            if ($e instanceof SafeException) {
                throw $e;
            }
            throw new SafeException(
                'Backup failed: ' . $e->getMessage(),
                [],
                $e,
            );
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * Decides whether a scheduled run is due. Returns true if the
     * configured frequency has elapsed since the most recent successful
     * scheduled-or-manual backup, or if scheduling is disabled (false).
     */
    public function isScheduledDue(): bool
    {
        $frequencyHours = $this->config->getInt('backups.frequency_hours', 24);
        if ($frequencyHours <= 0) {
            return false;
        }

        $last = $this->backups->lastForTrigger(BackupTrigger::SCHEDULED)
            ?? $this->backups->lastForTrigger(BackupTrigger::MANUAL)
            ?? $this->backups->lastForTrigger(BackupTrigger::PRE_UPDATE);

        if ($last === null || $last->status() !== BackupStatus::SUCCESS) {
            return true;
        }

        $lastTs = strtotime($last->createdAt() . ' UTC');
        if ($lastTs === false) {
            return true;
        }
        $elapsedHours = (time() - $lastTs) / 3600;
        return $elapsedHours >= $frequencyHours;
    }

    private function prune(StorageTarget $target, string $excludeTrigger): void
    {
        $retention = $this->config->getInt('backups.retention_count', 7);
        if ($retention <= 0) {
            return;
        }

        $recent = $this->backups->recentSuccessful($target->name(), $retention + 50);
        $toDelete = array_slice($recent, $retention);

        foreach ($toDelete as $stale) {
            try {
                $target->delete($stale->storageKey());
                $this->backups->delete($stale->id());
                $this->logger->info('Pruned old backup.', [
                    'id' => $stale->id(),
                    'key' => $stale->storageKey(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to prune old backup; continuing.', [
                    'id' => $stale->id(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function tempDir(): string
    {
        $base = sys_get_temp_dir() . '/stead-backup-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0775, true) && !is_dir($base)) {
            throw new SafeException('Could not create backup temp directory.');
        }
        return $base;
    }

    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($dir);
    }

    private function readAppVersion(): string
    {
        $root = $this->config->projectRoot();
        $file = $root . '/VERSION';
        if (!is_file($file)) {
            return '';
        }
        $contents = trim((string) @file_get_contents($file));
        return $contents;
    }
}
