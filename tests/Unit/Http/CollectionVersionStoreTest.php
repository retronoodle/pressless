<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Stead\Http\Cache\CollectionVersionStore;

final class CollectionVersionStoreTest extends TestCase
{
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->cacheRoot = sys_get_temp_dir() . '/stead-versions-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheRoot)) {
            foreach (glob($this->cacheRoot . '/public/versions/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheRoot . '/public/versions');
            @rmdir($this->cacheRoot . '/public');
            @rmdir($this->cacheRoot);
        }
    }

    public function testGetReturnsZeroWhenFileMissing(): void
    {
        $store = new CollectionVersionStore($this->cacheRoot);

        $this->assertSame(0, $store->get(42));
    }

    public function testBumpCreatesFileAndIncrements(): void
    {
        $store = new CollectionVersionStore($this->cacheRoot);

        $this->assertSame(1, $store->bump(1));
        $this->assertSame(2, $store->bump(1));
        $this->assertSame(3, $store->bump(1));
        $this->assertSame(3, $store->get(1));
    }

    public function testIndependentCollectionsHaveIndependentCounters(): void
    {
        $store = new CollectionVersionStore($this->cacheRoot);

        $store->bump(1);
        $store->bump(1);
        $store->bump(2);

        $this->assertSame(2, $store->get(1));
        $this->assertSame(1, $store->get(2));
        $this->assertSame(0, $store->get(99));
    }

    public function testBumpLazyCreatesVersionDirectory(): void
    {
        $store = new CollectionVersionStore($this->cacheRoot);
        $this->assertDirectoryDoesNotExist($this->cacheRoot . '/public/versions');

        $store->bump(7);

        $this->assertDirectoryExists($this->cacheRoot . '/public/versions');
        $this->assertFileExists($this->cacheRoot . '/public/versions/7');
    }
}
