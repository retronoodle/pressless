<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Stead\Config\Dotenv;

final class DotenvTest extends TestCase
{
    private string $tmpFile;
    /** @var string[] */
    private array $setEnv = [];

    protected function tearDown(): void
    {
        if (isset($this->tmpFile) && is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
        foreach ($this->setEnv as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->setEnv = [];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->setEnv[] = $name;
    }

    private function tmp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dotenv-');
        file_put_contents($path, $contents);
        $this->tmpFile = $path;
        return $path;
    }

    public function testParsesBasicKeyValuePairs(): void
    {
        $path = $this->tmp("FOO=bar\nBAZ=qux\n");
        $values = Dotenv::load($path);
        $this->assertSame('bar', $values['FOO']);
        $this->assertSame('qux', $values['BAZ']);
    }

    public function testSkipsCommentsAndBlankLines(): void
    {
        $path = $this->tmp("# header\n\nFOO=bar\n# comment\nBAZ=qux\n");
        $values = Dotenv::load($path);
        $this->assertCount(2, $values);
    }

    public function testStripsExportPrefix(): void
    {
        $path = $this->tmp("export FOO=bar\n");
        $values = Dotenv::load($path);
        $this->assertSame('bar', $values['FOO']);
    }

    public function testHandlesQuotedValues(): void
    {
        $this->setEnv('FOO', '');
        $this->setEnv('BAR', '');
        $path = $this->tmp("FOO=\"hello world\"\nBAR='single quoted'\n");
        $values = Dotenv::load($path);
        $this->assertSame('hello world', $values['FOO']);
        $this->assertSame('single quoted', $values['BAR']);
    }

    public function testIgnoresInvalidKeys(): void
    {
        $path = $this->tmp("lowercase=skip\n123_NUMERIC=skip\nVALID=ok\n");
        $values = Dotenv::load($path);
        $this->assertSame(['VALID' => 'ok'], $values);
    }

    public function testMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], Dotenv::load('/nonexistent/.env'));
    }
}
