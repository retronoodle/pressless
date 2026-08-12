<?php

declare(strict_types=1);

namespace Stead\Backups\Archive;

use Stead\Exception\SafeException;

/**
 * Builds a single backup archive per run.
 *
 * Layout (ZipArchive, stored with deflate):
 *
 *   manifest.json   — version, timestamp, db driver, file list, checksums
 *   dump.sql        — DB dump (mysqldump output, PDO fallback, or sqlite file copy as SQL)
 *   media/...       — recursive copy of paths.storage
 *
 * Single archive per run is simpler to list, retain, transfer to S3,
 * and restore atomically than tracking DB/media as separate paired
 * files.
 */
final class ArchiveBuilder
{
    public function __construct(private readonly string $mediaRoot)
    {
    }

    /**
     * @param array{db_driver: string, app_version: string} $context
     */
    public function build(
        string $archivePath,
        string $dumpPath,
        string $dumpNameInArchive,
        array $context,
    ): int {
        $zip = new \ZipArchive();
        $openCode = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($openCode !== true) {
            throw new SafeException(sprintf(
                'Could not open archive "%s" for writing (ZipArchive code %d).',
                $archivePath,
                $openCode,
            ));
        }

        try {
            // 1. Add the DB dump.
            if (!is_file($dumpPath)) {
                throw new SafeException(sprintf('Dump file "%s" not found.', $dumpPath));
            }
            if (!$zip->addFile($dumpPath, $dumpNameInArchive)) {
                throw new SafeException('Could not add dump file to archive.');
            }
            $dumpChecksum = hash_file('sha256', $dumpPath);

            // 2. Add the media directory recursively, skipping the
            //    plugins/ directory (out of scope per design.md).
            $files = $this->addMediaDirectory($zip);

            // 3. Write the manifest last so it can list everything
            //    that was actually added.
            $manifest = [
                'schema_version' => 1,
                'generated_at' => gmdate('c'),
                'db_driver' => $context['db_driver'],
                'app_version' => $context['app_version'],
                'archive_format' => 'zip',
                'entries' => [
                    [
                        'path' => $dumpNameInArchive,
                        'sha256' => $dumpChecksum,
                        'bytes' => (int) @filesize($dumpPath),
                    ],
                ],
                'media_files' => count($files),
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($manifestJson === false) {
                throw new SafeException('Could not serialise archive manifest.');
            }
            $zip->addFromString('manifest.json', $manifestJson);
        } finally {
            $zip->close();
        }

        $size = @filesize($archivePath);
        return $size === false ? 0 : $size;
    }

    /**
     * Adds the configured media directory recursively into the archive
     * under the prefix `media/`. Skips the `plugins/` directory (per
     * design.md: plugin code is out of scope for v1 backups).
     *
     * @return list<string> Relative paths of files added.
     */
    private function addMediaDirectory(\ZipArchive $zip): array
    {
        if (!is_dir($this->mediaRoot)) {
            return [];
        }

        $base = rtrim($this->mediaRoot, '/');
        $len = strlen($base) + 1;
        $added = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            $abs = $file->getPathname();
            $rel = substr($abs, $len);

            // Skip plugin code (v1 scope: media data only).
            if ($rel === 'plugins' || str_starts_with($rel, 'plugins/')) {
                continue;
            }

            // Convert OS-specific separators to forward slashes for
            // archive paths (ZipArchive convention).
            $archivePath = 'media/' . str_replace('\\', '/', $rel);
            if ($zip->addFile($abs, $archivePath)) {
                $added[] = $archivePath;
            }
        }

        return $added;
    }
}
