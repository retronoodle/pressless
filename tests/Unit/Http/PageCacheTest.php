<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Stead\Http\Cache\PageCache;

final class PageCacheTest extends TestCase
{
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->cacheRoot = sys_get_temp_dir() . '/stead-pagecache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheRoot . '/public/pages')) {
            foreach (glob($this->cacheRoot . '/public/pages/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheRoot . '/public/pages');
            @rmdir($this->cacheRoot . '/public');
            @rmdir($this->cacheRoot);
        }
    }

    public function testFirstCallRendersAndCachesResult(): void
    {
        $cache = new PageCache($this->cacheRoot);
        $calls = 0;

        $body = $cache->remember('home:1:0', function () use (&$calls): string {
            $calls++;
            return '<html>rendered</html>';
        });

        $this->assertSame(1, $calls);
        $this->assertSame('<html>rendered</html>', $body);
        $this->assertFileExists($this->cacheRoot . '/public/pages/' . sha1('home:1:0') . '.html');
    }

    public function testSecondCallServesFromCacheWithoutInvokingRenderer(): void
    {
        $cache = new PageCache($this->cacheRoot);
        $cache->remember('home:1:0', static fn (): string => 'first');

        $calls = 0;
        $body = $cache->remember('home:1:0', function () use (&$calls): string {
            $calls++;
            return 'should-not-render';
        });

        $this->assertSame(0, $calls, 'renderer must not be called on a cache hit');
        $this->assertSame('first', $body);
    }

    public function testDifferentKeysAreCachedSeparately(): void
    {
        $cache = new PageCache($this->cacheRoot);

        $a = $cache->remember('key-a', static fn (): string => 'A');
        $b = $cache->remember('key-b', static fn (): string => 'B');

        $this->assertSame('A', $a);
        $this->assertSame('B', $b);
    }
}
