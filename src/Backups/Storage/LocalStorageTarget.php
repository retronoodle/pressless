<?php

declare(strict_types=1);

namespace Stead\Backups\Storage;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;

/**
 * Filesystem storage target — writes archives into a configured
 * directory outside the public web root (default `var/backups`).
 *
 * Mirrors the LocalStorage pattern used by the media library so the
 * filesystem layout is uniform: one file per backup, named by the
 * remote key.
 */
final class LocalStorageTarget implements StorageTarget
{
    public function __construct(private readonly Configuration $config)
    {
    }

    public function name(): string
    {
        return 'local';
    }

    public function put(string $remoteKey, string $localPath): void
    {
        $target = $this->absolutePath($remoteKey);
        $this->ensureDirectory(dirname($target));
        if (!@copy($localPath, $target)) {
            // Fall back to a streamed copy if `copy()` fails on hosts
            // that disallow whole-file copies (rare, but seen on
            // hardened LXC containers).
            $this->streamedCopy($localPath, $target);
        }
    }

    public function get(string $remoteKey, string $localPath): int
    {
        $source = $this->absolutePath($remoteKey);
        if (!is_file($source)) {
            throw new SafeException(sprintf('Backup "%s" not found on local target.', $remoteKey));
        }
        if (!@copy($source, $localPath)) {
            $this->streamedCopy($source, $localPath);
        }
        $size = @filesize($localPath);
        return $size === false ? 0 : $size;
    }

    public function delete(string $remoteKey): void
    {
        $target = $this->absolutePath($remoteKey);
        if (is_file($target)) {
            @unlink($target);
        }
    }

    public function exists(string $remoteKey): bool
    {
        return is_file($this->absolutePath($remoteKey));
    }

    public function list(): array
    {
        $root = $this->rootDir();
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($root . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    private function rootDir(): string
    {
        $relative = $this->config->getString('backups.local_path', 'var/backups');
        if ($relative === '') {
            $relative = 'var/backups';
        }
        $absolute = $this->config->projectRoot() . '/' . ltrim($relative, '/');
        $real = realpath($absolute);
        return $real !== false ? $real : $absolute;
    }

    private function absolutePath(string $remoteKey): string
    {
        $this->assertSafeKey($remoteKey);
        return $this->rootDir() . '/' . $remoteKey;
    }

    /**
     * Reject keys with path separators or traversal sequences so a
     * bug elsewhere can't escape the configured backup root.
     */
    private function assertSafeKey(string $remoteKey): void
    {
        if ($remoteKey === '' || str_contains($remoteKey, '/') || str_contains($remoteKey, '\\')) {
            throw new SafeException(sprintf('Invalid backup storage key "%s".', $remoteKey));
        }
        if ($remoteKey === '.' || $remoteKey === '..') {
            throw new SafeException(sprintf('Invalid backup storage key "%s".', $remoteKey));
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new SafeException(sprintf('Could not create directory "%s".', $path));
        }
    }

    private function streamedCopy(string $from, string $to): void
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            throw new SafeException(sprintf('Could not copy "%s" to "%s".', $from, $to));
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new SafeException('Read failure during streamed copy.');
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
