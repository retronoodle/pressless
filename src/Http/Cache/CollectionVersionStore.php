<?php

declare(strict_types=1);

namespace Stead\Http\Cache;

/**
 * Tracks a per-collection version counter used to invalidate the public
 * page cache when entries change.
 *
 * The counter is intentionally a small file in the cache directory rather
 * than a column on the `collections` table: nothing else needs to query
 * it, and a `rm -rf` of the cache directory resets versions consistently
 * alongside the cached pages.
 *
 * The lazy-mkdir pattern matches {@see \Stead\View\TwigRenderer}: the
 * cache directory is created on first write, not on construction.
 */
final class CollectionVersionStore
{
    public function __construct(private readonly string $cacheRoot)
    {
    }

    public function get(int $collectionId): int
    {
        $path = $this->path($collectionId);
        if (!is_file($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        $value = (int) trim($raw);
        return $value < 0 ? 0 : $value;
    }

    public function bump(int $collectionId): int
    {
        $this->ensureDir();
        $path = $this->path($collectionId);
        $current = 0;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $current = (int) trim($raw);
                if ($current < 0) {
                    $current = 0;
                }
            }
        }
        $next = $current + 1;
        file_put_contents($path, (string) $next, LOCK_EX);
        return $next;
    }

    private function path(int $collectionId): string
    {
        return $this->cacheRoot . '/public/versions/' . $collectionId;
    }

    private function ensureDir(): void
    {
        $dir = $this->cacheRoot . '/public/versions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
