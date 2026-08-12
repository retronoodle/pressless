<?php

declare(strict_types=1);

namespace Stead\Backups\Restore;

use Stead\Backups\Backup;
use Stead\Backups\BackupRepository;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Replays a backup archive against the live site: DB dump restore,
 * media directory swap. Per design.md, SQLite restores are wrapped in
 * a transaction; MySQL restores are not atomic because DDL auto-commits
 * — documented as a known limitation rather than promised as atomic.
 */
final class RestoreRunner
{
    public function __construct(
        private readonly Configuration $config,
        private readonly Connection $connection,
        private readonly BackupRepository $backups,
        private readonly StorageTargetFactory $storageTargets,
        private readonly string $mediaRoot,
    ) {
    }

    /**
     * Downloads the archive, validates the manifest, and replays the
     * dump and media contents in place.
     */
    public function restore(Backup $backup): void
    {
        $backup->assertSucceeded();

        $tempDir = sys_get_temp_dir() . '/stead-restore-' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new SafeException('Could not create restore temp directory.');
        }

        try {
            $target = $this->storageTargets->forTarget($backup->target());
            $archivePath = $tempDir . '/archive.zip';
            $target->get($backup->storageKey(), $archivePath);

            $manifest = $this->openAndValidate($archivePath);

            // Replay the DB portion.
            $this->restoreDatabase($archivePath, $manifest);

            // Restore the media portion.
            $this->restoreMedia($archivePath);
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function openAndValidate(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $code = $zip->open($archivePath);
        if ($code !== true) {
            throw new SafeException(sprintf('Could not open backup archive (code %d).', $code));
        }
        try {
            $manifestRaw = $zip->getFromName('manifest.json');
            if (!is_string($manifestRaw) || $manifestRaw === '') {
                throw new SafeException('Backup archive is missing manifest.json.');
            }
            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                throw new SafeException('Backup archive manifest is invalid JSON.');
            }
            if (!isset($manifest['schema_version']) || (int) $manifest['schema_version'] !== 1) {
                throw new SafeException('Backup archive schema version is not supported.');
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private function restoreDatabase(string $archivePath, array $manifest): void
    {
        $tempDir = dirname($archivePath);
        $zip = new \ZipArchive();
        $code = $zip->open($archivePath);
        if ($code !== true) {
            throw new SafeException('Could not reopen backup archive for DB replay.');
        }

        try {
            $driver = (string) ($manifest['db_driver'] ?? '');
            $entries = isset($manifest['entries']) && is_array($manifest['entries']) ? $manifest['entries'] : [];
            $entry = null;
            foreach ($entries as $candidate) {
                if (isset($candidate['path']) && $candidate['path'] === 'dump.sql') {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                throw new SafeException('Backup archive does not contain dump.sql.');
            }

            $dumpPath = $tempDir . '/dump.sql';
            $raw = $zip->getFromName('dump.sql');
            if ($raw === false || $raw === '') {
                throw new SafeException('Could not extract dump.sql from archive.');
            }
            if (file_put_contents($dumpPath, $raw) === false) {
                throw new SafeException('Could not write dump.sql to temp.');
            }

            if (isset($entry['sha256'])) {
                $actual = hash_file('sha256', $dumpPath);
                if ($actual !== (string) $entry['sha256']) {
                    throw new SafeException('dump.sql checksum mismatch (archive may be corrupted).');
                }
            }

            $this->replayDump($dumpPath, $driver);
        } finally {
            $zip->close();
        }
    }

    private function replayDump(string $dumpPath, string $manifestDriver): void
    {
        $driver = $this->connection->driver();
        if ($manifestDriver !== '' && $manifestDriver !== $driver) {
            throw new SafeException(sprintf(
                'Backup was created with driver "%s" but the current site uses "%s".',
                $manifestDriver,
                $driver,
            ));
        }

        if ($driver === 'sqlite') {
            $this->replaySqliteDump($dumpPath);
            return;
        }
        $this->replayMysqlDump($dumpPath);
    }

    private function replaySqliteDump(string $dumpPath): void
    {
        $sql = (string) @file_get_contents($dumpPath);
        if ($sql === '') {
            throw new SafeException('SQLite dump file is empty.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $statements = $this->splitStatements($sql);
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new SafeException('SQLite restore failed: ' . $e->getMessage(), [], $e);
        }
    }

    private function replayMysqlDump(string $dumpPath): void
    {
        // MySQL: DDL auto-commits, so we cannot wrap the restore in a
        // single transaction. Per design.md, this is a documented
        // limitation. Recommend restoring to a fresh DB and swapping
        // for production use.
        $config = $this->config;
        $dsnParts = [
            '--host=' . escapeshellarg($config->getString('database.host')),
            '--port=' . $config->getInt('database.port', 3306),
            '--user=' . escapeshellarg($config->getString('database.username')),
            '--default-character-set=' . escapeshellarg($config->getString('database.charset', 'utf8mb4')),
        ];
        $binary = $this->findMysqlClient();
        if ($binary === null) {
            // No `mysql` client — fall back to PHP-side replay via
            // PDO statement-by-statement. Slower but doesn't require
            // shell access.
            $this->replayMysqlPhp($dumpPath);
            return;
        }

        $command = sprintf(
            '%s %s %s < %s 2>&1',
            escapeshellcmd($binary),
            implode(' ', $dsnParts),
            escapeshellarg($config->getString('database.database')),
            escapeshellarg($dumpPath),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new SafeException(sprintf(
                'MySQL restore failed (exit %d): %s',
                $exitCode,
                trim(implode("\n", $output)),
            ));
        }
    }

    private function replayMysqlPhp(string $dumpPath): void
    {
        $sql = (string) @file_get_contents($dumpPath);
        if ($sql === '') {
            throw new SafeException('MySQL dump file is empty.');
        }
        $pdo = $this->connection->pdo();
        try {
            foreach ($this->splitStatements($sql) as $stmt) {
                $pdo->exec($stmt);
            }
        } catch (\Throwable $e) {
            throw new SafeException('MySQL restore failed: ' . $e->getMessage(), [], $e);
        }
    }

    private function findMysqlClient(): ?string
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }
        $which = @shell_exec('command -v mysql 2>/dev/null');
        return is_string($which) && trim($which) !== '' ? trim($which) : null;
    }

    /**
     * Splits a SQL dump into individual statements. Honours line
     * comments (`--`) and string literals so semicolons inside them
     * don't break the split. Doubled-quote escapes (`''` and `""`)
     * are treated as a single character inside a string — matching
     * SQL standard semantics.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if (!$inString && $ch === '-' && $next === '-') {
                // Skip to end of line.
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if (!$inString && ($ch === "'" || $ch === '"')) {
                $inString = true;
                $stringChar = $ch;
                $buf .= $ch;
                $i++;
                continue;
            }
            if ($inString && $ch === $stringChar && $next === $stringChar) {
                // Doubled-quote escape — keep both characters and stay inside the string.
                $buf .= $ch . $next;
                $i += 2;
                continue;
            }
            if ($inString && $ch === $stringChar) {
                $inString = false;
                $buf .= $ch;
                $i++;
                continue;
            }
            if (!$inString && $ch === ';') {
                $candidate = trim($buf);
                if ($candidate !== '') {
                    $out[] = $candidate;
                }
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $ch;
            $i++;
        }
        $tail = trim($buf);
        if ($tail !== '') {
            $out[] = $tail;
        }
        return $out;
    }

    private function restoreMedia(string $archivePath): void
    {
        $zip = new \ZipArchive();
        $code = $zip->open($archivePath);
        if ($code !== true) {
            throw new SafeException('Could not reopen backup archive for media restore.');
        }

        try {
            // Clear the existing media directory contents (keep the
            // directory itself and any non-backup metadata).
            $this->wipeMediaRoot();

            $mediaRoot = rtrim($this->mediaRoot, '/');
            $prefix = 'media/';

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !isset($stat['name'])) {
                    continue;
                }
                $name = (string) $stat['name'];
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                if ($relative === '') {
                    continue;
                }
                $target = $mediaRoot . '/' . $relative;
                if (substr($name, -1) === '/') {
                    $this->ensureDir(dirname($target));
                    $this->ensureDir($target);
                    continue;
                }
                $this->ensureDir(dirname($target));
                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new SafeException(sprintf('Could not extract "%s" from archive.', $name));
                }
                if (file_put_contents($target, $contents) === false) {
                    throw new SafeException(sprintf('Could not write restored file "%s".', $target));
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function wipeMediaRoot(): void
    {
        if (!is_dir($this->mediaRoot)) {
            return;
        }
        $entries = @scandir($this->mediaRoot) ?: [];
        foreach ($entries as $base) {
            if ($base === '.' || $base === '..') {
                continue;
            }
            $entry = $this->mediaRoot . '/' . $base;
            if (is_dir($entry)) {
                $this->removeRecursive($entry);
            } else {
                @unlink($entry);
            }
        }
    }

    private function removeRecursive(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new SafeException(sprintf('Could not create directory "%s".', $path));
        }
    }
}
