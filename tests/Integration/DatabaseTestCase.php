<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Database\Resetter;

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
        $tmp = sys_get_temp_dir() . '/stead-db-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
        mkdir($tmp . '/config', 0775, true);
        mkdir($tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $tmp . '/database/migrations/' . basename($src));
        }

        $driver = static::driver();
        $database = $driver === 'sqlite' ? ':memory:' : 'stead_test';

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
                'sessions' => ['name' => 'stead_session'],
            ],
        );
        return $config;
    }
}
