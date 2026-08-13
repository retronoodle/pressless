<?php

declare(strict_types=1);

namespace Stead\Themes;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Validates an uploaded theme ZIP and installs it under `themes/<slug>/`.
 *
 * Every entry name is checked before any write. Extraction lands in a
 * staging directory that is atomically renamed into place on success.
 */
final class ThemeInstaller
{
    public const REQUIRED_TEMPLATES = ['base.twig', 'home.twig', 'collection.twig', 'entry.twig'];

    public const MAX_ENTRIES = 500;

    /** @var list<string> */
    private const ZIP_MIME_TYPES = [
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/zip-compressed',
    ];

    public function __construct(
        private readonly ThemeRepository $themes,
        private readonly Configuration $config,
    ) {
    }

    public function install(UploadedFile $file): Theme
    {
        $this->assertSize($file);
        $this->assertMime($file);

        $path = $file->getPathname();
        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new SafeException('Uploaded file could not be read.');
        }

        $zip = new \ZipArchive();
        $code = $zip->open($path);
        if ($code !== true) {
            throw new SafeException(sprintf('Could not open theme archive (code %d).', $code));
        }

        try {
            $entries = $this->validatedEntries($zip);
            $topLevel = $this->requireSingleTopLevelFolder($entries);
            $slug = $this->slugFromFolder($topLevel);
            $this->assertRequiredTemplates($entries, $topLevel);
            $this->assertSlugAvailable($slug);

            $manifest = $this->readManifest($zip, $topLevel, $slug);
            $destination = $this->themeDirectory($slug);
            $this->extractAtomically($zip, $entries, $topLevel, $destination);
        } finally {
            $zip->close();
        }

        try {
            return $this->themes->create(
                $slug,
                $manifest['name'],
                $manifest['version'],
                $manifest['author'],
            );
        } catch (\Throwable $e) {
            $this->removeRecursive($destination);
            throw $e;
        }
    }

    public function removeThemeFiles(string $slug): void
    {
        $directory = $this->themeDirectory($slug);
        if (is_dir($directory)) {
            $this->removeRecursive($directory);
        }
    }

    private function assertSize(UploadedFile $file): void
    {
        $maxBytes = $this->config->getInt('theme.max_zip_bytes', 10 * 1024 * 1024);
        $size = $file->getSize();
        if ($maxBytes > 0 && $size !== false && $size > $maxBytes) {
            throw new SafeException(sprintf(
                'Theme ZIP is too large. Maximum allowed size is %d bytes.',
                $maxBytes,
            ));
        }
    }

    private function assertMime(UploadedFile $file): void
    {
        $path = $file->getPathname();
        if (!is_string($path) || !is_file($path)) {
            throw new SafeException('Uploaded file could not be read.');
        }
        $mime = $this->detectMime($path);
        $looksLikeZip = $this->hasZipMagic($path);
        if ($mime !== null && in_array($mime, self::ZIP_MIME_TYPES, true)) {
            return;
        }
        if ($looksLikeZip && ($mime === null || $mime === 'application/octet-stream')) {
            return;
        }
        throw new SafeException('File type not allowed. Upload a ZIP archive.');
    }

    private function detectMime(string $path): ?string
    {
        $detected = @finfo_open(FILEINFO_MIME_TYPE);
        if ($detected === false) {
            return null;
        }
        $mime = @finfo_file($detected, $path);
        @finfo_close($detected);
        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function hasZipMagic(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = (string) fread($handle, 4);
        fclose($handle);
        return str_starts_with($header, "PK\x03\x04")
            || str_starts_with($header, "PK\x05\x06")
            || str_starts_with($header, "PK\x07\x08");
    }

    /**
     * @return list<array{name: string, index: int, is_dir: bool}>
     */
    private function validatedEntries(\ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        if ($count < 1) {
            throw new SafeException('Theme ZIP is empty.');
        }
        if ($count > self::MAX_ENTRIES) {
            throw new SafeException(sprintf(
                'Theme ZIP has too many entries (maximum %d).',
                self::MAX_ENTRIES,
            ));
        }

        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new SafeException('Theme ZIP contains an unreadable entry.');
            }
            $name = (string) $stat['name'];
            if ($name === '') {
                throw new SafeException('Theme ZIP contains an entry with an empty name.');
            }
            if (str_contains($name, '..')) {
                throw new SafeException('Theme ZIP contains an unsafe entry path.');
            }
            if ($this->isAbsolutePath($name)) {
                throw new SafeException('Theme ZIP contains an unsafe entry path.');
            }
            if ($this->isSymlinkEntry($zip, $i)) {
                throw new SafeException('Theme ZIP contains a symlink entry.');
            }
            $entries[] = [
                'name' => $name,
                'index' => $i,
                'is_dir' => str_ends_with($name, '/'),
            ];
        }
        return $entries;
    }

    private function isAbsolutePath(string $name): bool
    {
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return true;
        }
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $name);
    }

    private function isSymlinkEntry(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }
        if ($opsys === \ZipArchive::OPSYS_UNIX) {
            $mode = ($attr >> 16) & 0xFFFF;
            return ($mode & 0xF000) === 0xA000;
        }
        return false;
    }

    /**
     * @param list<array{name: string, index: int, is_dir: bool}> $entries
     */
    private function requireSingleTopLevelFolder(array $entries): string
    {
        $folders = [];
        foreach ($entries as $entry) {
            $normalized = str_replace('\\', '/', $entry['name']);
            $parts = explode('/', $normalized);
            $top = $parts[0];
            if ($top === '') {
                throw new SafeException('A single theme folder is required inside the ZIP.');
            }
            if (!str_contains($normalized, '/')) {
                throw new SafeException('A single theme folder is required inside the ZIP.');
            }
            $folders[$top] = true;
        }
        if (count($folders) !== 1) {
            throw new SafeException('A single theme folder is required inside the ZIP.');
        }
        return array_key_first($folders);
    }

    /**
     * @param list<array{name: string, index: int, is_dir: bool}> $entries
     */
    private function assertRequiredTemplates(array $entries, string $topLevel): void
    {
        $present = [];
        $prefix = $topLevel . '/';
        foreach ($entries as $entry) {
            $normalized = str_replace('\\', '/', $entry['name']);
            if (!str_starts_with($normalized, $prefix)) {
                continue;
            }
            $relative = substr($normalized, strlen($prefix));
            if (in_array($relative, self::REQUIRED_TEMPLATES, true)) {
                $present[$relative] = true;
            }
        }
        $missing = [];
        foreach (self::REQUIRED_TEMPLATES as $required) {
            if (!isset($present[$required])) {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            throw new SafeException(
                'Theme is missing required templates: ' . implode(', ', $missing) . '.',
            );
        }
    }

    private function slugFromFolder(string $folder): string
    {
        $lowered = strtolower($folder);
        $collapsed = (string) preg_replace('/[^a-z0-9]+/', '-', $lowered);
        $slug = trim($collapsed, '-');
        if ($slug === '') {
            throw new SafeException('Theme folder name could not be turned into a valid slug.');
        }
        if (strlen($slug) > 190) {
            $slug = substr($slug, 0, 190);
            $slug = rtrim($slug, '-');
        }
        return $slug;
    }

    private function assertSlugAvailable(string $slug): void
    {
        if ($this->themes->findBySlug($slug) !== null) {
            throw new SafeException(sprintf('A theme named "%s" already exists.', $slug));
        }
        if (is_dir($this->themeDirectory($slug))) {
            throw new SafeException(sprintf('A theme named "%s" already exists.', $slug));
        }
    }

    /**
     * @return array{name: string, version: string, author: string}
     */
    private function readManifest(\ZipArchive $zip, string $topLevel, string $slug): array
    {
        $fallback = $this->nameFromSlug($slug);
        $raw = $zip->getFromName($topLevel . '/theme.json');
        if ($raw === false) {
            return ['name' => $fallback, 'version' => '', 'author' => ''];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['name' => $fallback, 'version' => '', 'author' => ''];
        }
        $name = $decoded['name'] ?? null;
        $version = $decoded['version'] ?? null;
        $author = $decoded['author'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = $fallback;
        }
        if (!is_string($version)) {
            $version = '';
        }
        if (!is_string($author)) {
            $author = '';
        }
        return ['name' => $name, 'version' => $version, 'author' => $author];
    }

    private function nameFromSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * @param list<array{name: string, index: int, is_dir: bool}> $entries
     */
    private function extractAtomically(\ZipArchive $zip, array $entries, string $topLevel, string $destination): void
    {
        $themesRoot = $this->themesRoot();
        if (!is_dir($themesRoot) && !@mkdir($themesRoot, 0775, true) && !is_dir($themesRoot)) {
            throw new SafeException('Could not create the themes directory.');
        }

        $staging = $themesRoot . '/.tmp-install-' . bin2hex(random_bytes(8));
        if (!@mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new SafeException('Could not create a staging directory for the theme.');
        }

        $prefix = $topLevel . '/';
        $stagingReal = realpath($staging);
        if ($stagingReal === false) {
            $this->removeRecursive($staging);
            throw new SafeException('Could not resolve the theme staging directory.');
        }

        try {
            foreach ($entries as $entry) {
                $normalized = str_replace('\\', '/', $entry['name']);
                if ($normalized === $topLevel || $normalized === $prefix) {
                    continue;
                }
                if (!str_starts_with($normalized, $prefix)) {
                    throw new SafeException('Theme ZIP contains an unsafe entry path.');
                }
                $relative = substr($normalized, strlen($prefix));
                if ($relative === '') {
                    continue;
                }
                $target = $stagingReal . '/' . $relative;
                $parent = dirname($target);
                if (!$this->isInside($parent, $stagingReal) && $parent !== $stagingReal) {
                    throw new SafeException('Theme ZIP contains an unsafe entry path.');
                }
                if ($entry['is_dir']) {
                    if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                        throw new SafeException('Could not extract theme directory.');
                    }
                    continue;
                }
                if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                    throw new SafeException('Could not extract theme directory.');
                }
                $contents = $zip->getFromIndex($entry['index']);
                if ($contents === false) {
                    throw new SafeException(sprintf('Could not extract "%s" from archive.', $entry['name']));
                }
                if (file_put_contents($target, $contents) === false) {
                    throw new SafeException(sprintf('Could not write theme file "%s".', $relative));
                }
                $written = realpath($target);
                if ($written === false || !$this->isInside($written, $stagingReal)) {
                    throw new SafeException('Theme ZIP contains an unsafe entry path.');
                }
            }

            if (!@rename($staging, $destination)) {
                throw new SafeException('Could not move the extracted theme into place.');
            }
        } catch (\Throwable $e) {
            $this->removeRecursive($staging);
            if (is_dir($destination)) {
                $this->removeRecursive($destination);
            }
            throw $e;
        }
    }

    private function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function themeDirectory(string $slug): string
    {
        return $this->themesRoot() . '/' . $slug;
    }

    private function themesRoot(): string
    {
        $relative = $this->config->getString('paths.theme');
        if ($relative === '') {
            $relative = 'themes';
        }
        return rtrim($this->config->projectRoot(), '/') . '/' . trim($relative, '/');
    }

    private function removeRecursive(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
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
}
