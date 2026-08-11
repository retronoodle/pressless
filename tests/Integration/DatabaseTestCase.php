<?php

declare(strict_types=1);

namespace Pressless\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pressless\Config\Configuration;
use Pressless\Database\Connection;
use Pressless\Database\Migrator;
use Pressless\Database\Resetter;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;
    protected Migrator $migrator;
    protected Configuration $config;

    protected function setUp(): void
    {
        $this->config = self::makeConfig();
        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    abstract protected static function driver(): string;

    protected static function makeConfig(): Configuration
    {
        $tmp = sys_get_temp_dir() . '/pressless-db-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
        mkdir($tmp . '/config', 0775, true);
        mkdir($tmp . '/database/migrations', 0775, true);
        copy(
            __DIR__ . '/../../database/migrations/20260811000001_initial_schema.sqlite.sql',
            $tmp . '/database/migrations/20260811000001_initial_schema.sqlite.sql',
        );

        $driver = static::driver();
        $database = $driver === 'sqlite' ? ':memory:' : 'pressless_test';

        $config = new Configuration(
            $tmp,
            'development',
            [
                'database' => [
                    'connection' => $driver,
                    'database' => $database,
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'username' => 'root',
                    'password' => '',
                    'charset' => 'utf8mb4',
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
                'sessions' => ['name' => 'pressless_session'],
            ],
        );
        return $config;
    }
}
