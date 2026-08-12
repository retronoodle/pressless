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
}
