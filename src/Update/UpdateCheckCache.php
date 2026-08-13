<?php

declare(strict_types=1);

namespace Stead\Update;

/**
 * Persists the result of an update check so admin page loads don't hammer
 * the release endpoint on every request.
 *
 * Stored as a single JSON file in the cache root (the same `var/cache/`
 * tree that {@see \Stead\Http\Cache\PageCache} uses), with a fixed name.
 * This is intentionally not a per-key cache: there is exactly one update
 * check per install, so a fixed filename is simpler than keying it and
 * keeps the on-disk layout grep-friendly for an operator who wants to
 * invalidate it by hand.
 *
 * Atomicity: writes go through a tempfile + rename so a concurrent read
 * (e.g. two admin page loads landing on different PHP-FPM workers at the
 * same time) never sees a half-written file.
 */
final class UpdateCheckCache
{
    private const FILENAME = 'update-check.json';

    public function __construct(private readonly string $cacheRoot)
    {
    }

    public function load(): ?UpdateCheckResult
    {
        $path = $this->path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $this->fromArray($decoded);
    }

    public function save(UpdateCheckResult $result): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $payload = json_encode($this->toArray($result), JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        @rename($tmp, $path);
    }

    public function clear(): void
    {
        $path = $this->path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(): string
    {
        return $this->cacheRoot . '/' . self::FILENAME;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data): ?UpdateCheckResult
    {
        $installed = $data['installed'] ?? null;
        $latest = $data['latest'] ?? null;
        $url = $data['download_url'] ?? null;
        $isUpToDate = $data['is_up_to_date'] ?? null;
        $error = $data['error'] ?? null;
        $fromCache = $data['from_cache'] ?? null;
        $checkedAt = $data['checked_at'] ?? null;
        $installedVersionReleasedAt = $data['installed_version_released_at'] ?? null;

        if (!is_string($installed) || !is_bool($isUpToDate) || !is_int($checkedAt)) {
            return null;
        }
        if ($latest !== null && !is_string($latest)) {
            return null;
        }
        if ($url !== null && !is_string($url)) {
            return null;
        }
        if ($error !== null && !is_string($error)) {
            return null;
        }
        // installed_version_released_at is optional and added later than
        // the rest of the schema; a missing or wrong-typed value must
        // never invalidate an otherwise-valid cache file.
        if ($installedVersionReleasedAt !== null && !is_string($installedVersionReleasedAt)) {
            $installedVersionReleasedAt = null;
        }

        return new UpdateCheckResult(
            installedVersion: $installed,
            latestVersion: $latest,
            downloadUrl: $url,
            isUpToDate: $isUpToDate,
            error: $error,
            fromCache: is_bool($fromCache) ? $fromCache : false,
            checkedAt: $checkedAt,
            installedVersionReleasedAt: $installedVersionReleasedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(UpdateCheckResult $result): array
    {
        return $result->toArray();
    }
}
