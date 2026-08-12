<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Stead\Config\EnvWriter;

final class EnvWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stead-env-' . bin2hex(random_bytes(4)) . '.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testWritesNewKeys(): void
    {
        EnvWriter::write($this->path, [
            'BACKUPS_TARGET' => 's3',
            'BACKUPS_RETENTION_COUNT' => '5',
        ]);

        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString('BACKUPS_TARGET=s3', $contents);
        self::assertStringContainsString('BACKUPS_RETENTION_COUNT=5', $contents);
        self::assertStringStartsWith('# Managed by Stead admin', $contents);
    }

    public function testUpdatesExistingKey(): void
    {
        file_put_contents($this->path, "BACKUPS_TARGET=local\n");

        EnvWriter::write($this->path, [
            'BACKUPS_TARGET' => 's3',
        ]);

        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString('BACKUPS_TARGET=s3', $contents);
        self::assertStringNotContainsString('BACKUPS_TARGET=local', $contents);
    }

    public function testPreservesUnrelatedKeys(): void
    {
        file_put_contents($this->path, "FOO=bar\nBAZ=qux\n");

        EnvWriter::write($this->path, [
            'BACKUPS_TARGET' => 's3',
        ]);

        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString('FOO=bar', $contents);
        self::assertStringContainsString('BAZ=qux', $contents);
        self::assertStringContainsString('BACKUPS_TARGET=s3', $contents);
    }

    public function testQuotesValuesWithSpecialChars(): void
    {
        EnvWriter::write($this->path, [
            'BACKUPS_S3_ENDPOINT' => 'https://example.com/path with spaces',
        ]);

        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString('BACKUPS_S3_ENDPOINT="https://example.com/path with spaces"', $contents);
    }
}
