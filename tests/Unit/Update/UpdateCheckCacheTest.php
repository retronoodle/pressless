<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use Stead\Update\UpdateCheckCache;
use Stead\Update\UpdateCheckResult;

final class UpdateCheckCacheTest extends TestCase
{
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->cacheRoot = sys_get_temp_dir() . '/stead-cache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheRoot);
    }

    public function testReturnsNullWhenNoCacheFileExists(): void
    {
        $cache = new UpdateCheckCache($this->cacheRoot);
        $this->assertNull($cache->load());
    }

    public function testRoundTripsResultThroughSaveAndLoad(): void
    {
        $cache = new UpdateCheckCache($this->cacheRoot);
        $result = new UpdateCheckResult(
            installedVersion: '1.0.0',
            latestVersion: '1.1.0',
            downloadUrl: 'https://example.test/stead-1.1.0.zip',
            isUpToDate: false,
            error: null,
            fromCache: false,
            checkedAt: 1700000000,
        );
        $cache->save($result);
        $loaded = $cache->load();
        $this->assertNotNull($loaded);
        $this->assertSame('1.0.0', $loaded->installedVersion);
        $this->assertSame('1.1.0', $loaded->latestVersion);
        $this->assertSame('https://example.test/stead-1.1.0.zip', $loaded->downloadUrl);
        $this->assertFalse($loaded->isUpToDate);
        $this->assertTrue($loaded->hasUpdate());
        $this->assertSame(1700000000, $loaded->checkedAt);
    }

    public function testReturnsNullForMalformedCacheFile(): void
    {
        file_put_contents($this->cacheRoot . '/update-check.json', '{not json');
        $cache = new UpdateCheckCache($this->cacheRoot);
        $this->assertNull($cache->load());
    }

    public function testClearRemovesTheFile(): void
    {
        $cache = new UpdateCheckCache($this->cacheRoot);
        $cache->save(new UpdateCheckResult(
            installedVersion: '1.0.0',
            latestVersion: null,
            downloadUrl: null,
            isUpToDate: true,
            error: null,
            fromCache: false,
            checkedAt: time(),
        ));
        $this->assertNotNull($cache->load());
        $cache->clear();
        $this->assertNull($cache->load());
    }

    public function testRoundTripsInstalledVersionReleasedAt(): void
    {
        $cache = new UpdateCheckCache($this->cacheRoot);
        $cache->save(new UpdateCheckResult(
            installedVersion: '1.0.0',
            latestVersion: '1.1.0',
            downloadUrl: 'https://example.test/stead-1.1.0.zip',
            isUpToDate: false,
            error: null,
            fromCache: false,
            checkedAt: 1700000000,
            installedVersionReleasedAt: '2026-08-13T12:34:56Z',
        ));
        $loaded = $cache->load();
        $this->assertNotNull($loaded);
        $this->assertSame('2026-08-13T12:34:56Z', $loaded->installedVersionReleasedAt);
    }

    public function testLoadsOldCacheFileWithNullInstalledVersionReleasedAt(): void
    {
        // Cache file written before the new field existed — no key at all.
        $payload = [
            'installed' => '1.0.0',
            'latest' => '1.1.0',
            'download_url' => 'https://example.test/stead-1.1.0.zip',
            'is_up_to_date' => false,
            'error' => null,
            'from_cache' => false,
            'checked_at' => 1700000000,
        ];
        file_put_contents(
            $this->cacheRoot . '/update-check.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $cache = new UpdateCheckCache($this->cacheRoot);
        $loaded = $cache->load();
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->installedVersionReleasedAt);
        $this->assertSame('1.0.0', $loaded->installedVersion);
    }

    public function testLoadsCacheFileWithMalformedInstalledVersionReleasedAt(): void
    {
        // Defensive: a wrong-typed value must be treated as null rather
        // than invalidating the whole cache entry.
        $payload = [
            'installed' => '1.0.0',
            'latest' => '1.1.0',
            'download_url' => 'https://example.test/stead-1.1.0.zip',
            'is_up_to_date' => false,
            'error' => null,
            'from_cache' => false,
            'checked_at' => 1700000000,
            'installed_version_released_at' => ['not' => 'a string'],
        ];
        file_put_contents(
            $this->cacheRoot . '/update-check.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $cache = new UpdateCheckCache($this->cacheRoot);
        $loaded = $cache->load();
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->installedVersionReleasedAt);
    }
}
