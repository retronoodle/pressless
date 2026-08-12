<?php

declare(strict_types=1);

namespace Stead\Installer;

use Stead\Exception\SafeException;

/**
 * Manages the `installed.lock` file that gates the installer.
 *
 * `installed.lock` is a plain empty file at the project root (sibling to
 * `.env`). It is created as the final installer step using exclusive-create
 * semantics so two concurrent completion attempts cannot both succeed; the
 * second completer detects the existing file and redirects to `/admin/login`
 * without re-running migrations or seeding.
 */
final class InstallerLock
{
    public static function isInstalled(string $projectRoot): bool
    {
        return is_file($projectRoot . '/installed.lock');
    }

    /**
     * Creates the lock file using an exclusive-create write so the first
     * completer wins. Returns true on success; returns false (without throwing)
     * if the file already exists, which the installer treats as a concurrent
     * completion and redirects.
     */
    public static function create(string $projectRoot): bool
    {
        $path = $projectRoot . '/installed.lock';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path)) {
                return false;
            }
            throw new SafeException(
                sprintf('Could not write "%s". Check that the project root is writable by the web server.', $path),
                ['path' => $path],
            );
        }
        fclose($handle);
        @chmod($path, 0644);
        return true;
    }
}