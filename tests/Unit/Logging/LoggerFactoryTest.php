<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Logging;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Pressless\Config\Configuration;
use Pressless\Logging\LoggerFactory;
use Pressless\Support\ProjectRoot;

final class LoggerFactoryTest extends TestCase
{
    public function testRedactsSensitiveKeysFromContext(): void
    {
        $record = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'pressless',
            level: \Monolog\Level::Debug,
            message: 'login attempt',
            context: [
                'email' => 'a@b.c',
                'password' => 'hunter2',
                'api_key' => 'sk-123',
                'session_payload' => 'long',
                'nested' => ['token' => 'abc'],
            ],
            extra: [],
        );

        $result = LoggerFactory::redactSecrets($record);
        $this->assertSame('[redacted]', $result->context['password']);
        $this->assertSame('[redacted]', $result->context['api_key']);
        $this->assertSame('[redacted]', $result->context['nested']['token']);
        $this->assertSame('a@b.c', $result->context['email']);
    }

    public function testFactoryCreatesLogger(): void
    {
        $tmp = sys_get_temp_dir() . '/pressless-log-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
        mkdir($tmp . '/config', 0775, true);
        $config = new Configuration($tmp, 'test', [
            'logging' => ['level' => 'debug', 'file' => 'test.log'],
            'paths' => ['cache' => 'var/cache', 'log' => 'var/log'],
        ]);
        $logger = LoggerFactory::create($config);
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->info('hello');
        $this->assertFileExists($tmp . '/var/log/test.log');
    }
}
