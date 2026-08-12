<?php

declare(strict_types=1);

namespace Stead\Media;

use Stead\Config\Configuration;

/**
 * Resolves paths and performs file I/O for uploaded media.
 *
 * Layout (sits under the configured `paths.storage` root, default
 * `storage/media/`):
 *
 *   {root}/{id}/{filename}           — original file
 *   {root}/{id}/{size}.{ext}         — generated transform variant
 *
 * The numeric id (the DB primary key) is the only directory segment
 * derived from user-controlled data; the client-supplied filename is
 * stored only as metadata and as a basename under that id directory,
 * never used to compose a path. That keeps every path server-controlled
 * and rules out directory traversal from the upload filename.
 */
final class LocalStorage
{
    public function __construct(private readonly Configuration $config)
    {
    }

    public function root(): string
    {
        return $this->resolveRoot();
    }

    public function directoryFor(int $id): string
    {
        return $this->resolveRoot() . '/' . $id;
    }

    /**
     * Copies the source file into the media directory for the given id and
     * returns the relative path (e.g. `5/photo.jpg`) that should be stored
     * on the `media` row.
     */
    public function writeOriginal(int $id, string $filename, string $sourcePath): string
    {
        $directory = $this->directoryFor($id);
        $this->ensureDirectory($directory);

        $safeName = $this->sanitizeFilename($filename);
        $target = $directory . '/' . $safeName;

        if (!@rename($sourcePath, $target)) {
            if (!@copy($sourcePath, $target)) {
                throw new \Stead\Exception\SafeException(sprintf(
                    'Could not move uploaded file into media storage at "%s".',
                    $target,
                ));
            }
            @unlink($sourcePath);
        }

        return $id . '/' . $safeName;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->resolveRoot() . '/' . ltrim($relativePath, '/');
    }

    /**
     * Returns the relative path for a transform variant of the given media
     * id. The extension is derived from the configured transform format
     * (`jpg` by default) so the variant is always a normalized image format.
     */
    public function transformPath(int $id, string $size, string $extension = 'jpg'): string
    {
        $safeSize = $this->sanitizeSize($size);
        $safeExt = $this->sanitizeExtension($extension);
        return $id . '/' . $safeSize . '.' . $safeExt;
    }

    public function absoluteTransformPath(int $id, string $size, string $extension = 'jpg'): string
    {
        return $this->resolveRoot() . '/' . $this->transformPath($id, $size, $extension);
    }

    public function ensureTransformDirectory(int $id): string
    {
        $dir = $this->directoryFor($id);
        $this->ensureDirectory($dir);
        return $dir;
    }

    private function resolveRoot(): string
    {
        $relative = $this->config->getString('paths.storage', 'storage/media');
        if ($relative === '') {
            $relative = 'storage/media';
        }
        $absolute = $this->config->projectRoot() . '/' . ltrim($relative, '/');
        $real = realpath($absolute);
        if ($real !== false) {
            return $real;
        }
        $this->ensureDirectory($absolute);
        $real = realpath($absolute);
        return $real !== false ? $real : $absolute;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \Stead\Exception\SafeException(sprintf('Could not create directory "%s".', $path));
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $base = basename($filename);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'upload';
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'upload';
        }
        if (strlen($base) > 200) {
            $base = substr($base, 0, 200);
        }
        return $base;
    }

    private function sanitizeSize(string $size): string
    {
        $clean = strtolower($size);
        if (!preg_match('/^[a-z0-9_-]+$/', $clean)) {
            throw new \Stead\Exception\SafeException(sprintf('Invalid transform size key "%s".', $size));
        }
        return $clean;
    }

    private function sanitizeExtension(string $extension): string
    {
        $clean = strtolower($extension);
        $clean = ltrim($clean, '.');
        if (!preg_match('/^[a-z0-9]+$/', $clean)) {
            throw new \Stead\Exception\SafeException(sprintf('Invalid transform extension "%s".', $extension));
        }
        return $clean;
    }
}
