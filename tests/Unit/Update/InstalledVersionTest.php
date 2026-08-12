<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use Stead\Update\InstalledVersion;

final class InstalledVersionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-version-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $versionPath = $this->projectRoot . '/VERSION';
        if (is_file($versionPath)) {
            unlink($versionPath);
        }
        if (is_dir($this->projectRoot)) {
            rmdir($this->projectRoot);
        }
    }

    public function testReadsVersionFromFile(): void
    {
        file_put_contents($this->projectRoot . '/VERSION', "1.2.3\n");
        $installed = new InstalledVersion($this->projectRoot);
        $this->assertSame('1.2.3', $installed->read());
    }

    public function testReturnsNullWhenFileMissing(): void
    {
        $installed = new InstalledVersion($this->projectRoot);
        $this->assertNull($installed->read());
    }

    public function testReturnsNullWhenFileIsEmpty(): void
    {
        file_put_contents($this->projectRoot . '/VERSION', "   \n");
        $installed = new InstalledVersion($this->projectRoot);
        $this->assertNull($installed->read());
    }
}
