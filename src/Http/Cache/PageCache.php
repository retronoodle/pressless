<?php

declare(strict_types=1);

namespace Stead\Http\Cache;

/**
 * A file-based read-through cache for rendered public pages.
 *
 * The caller builds a key from the route + parameters + every collection
 * version that page depends on (so a version bump changes the key and
 * causes the next request to render fresh). On a hit the cached file is
 * returned verbatim; on a miss the supplied renderer is called and its
 * output is written to the cache before being returned.
 *
 * The cache directory is created lazily on first write — the same pattern
 * {@see \Stead\View\TwigRenderer} uses for its compiled-template cache.
 */
final class PageCache
{
    public function __construct(private readonly string $cacheRoot)
    {
    }

    /**
     * @param callable(): string $render
     */
    public function remember(string $key, callable $render): string
    {
        $path = $this->path($key);
        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }

        $body = $render();
        $this->ensureDir();
        file_put_contents($path, $body, LOCK_EX);
        return $body;
    }

    private function path(string $key): string
    {
        return $this->cacheRoot . '/public/pages/' . sha1($key) . '.html';
    }

    private function ensureDir(): void
    {
        $dir = $this->cacheRoot . '/public/pages';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
