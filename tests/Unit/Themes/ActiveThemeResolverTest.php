<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeRepository;

final class ActiveThemeResolverTest extends TestCase
{
    private string $root;
    private Configuration $config;
    private Connection $connection;
    private ThemeRepository $repository;
    private ActiveThemeResolver $resolver;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/stead-theme-resolver-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/log', 0775, true);
        mkdir($this->root . '/database/migrations', 0775, true);
        mkdir($this->root . '/themes/starter', 0775, true);
        mkdir($this->root . '/themes/fallback', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->root . '/database/migrations/' . basename($src));
        }
        $this->config = new Configuration(
            $this->root,
            'development',
            [
                'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                    'theme' => 'themes',
                ],
                'theme' => ['active' => 'fallback'],
            ],
        );
        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();
        $this->repository = new ThemeRepository($this->connection);
        $this->resolver = new ActiveThemeResolver($this->repository, $this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->rrmdir($this->root);
    }

    public function testUsesTheDatabaseActiveThemeWhenItsDirectoryExists(): void
    {
        $resolved = $this->resolver->resolveThemeDirectory();

        $this->assertSame($this->root . '/themes/starter', $resolved);
    }

    public function testMissingDirectoryFallsBackToConfig(): void
    {
        rmdir($this->root . '/themes/starter');

        $resolved = $this->resolver->resolveThemeDirectory();

        $this->assertSame($this->root . '/themes/fallback', $resolved);
    }

    public function testDatabaseExceptionFallsBackToConfig(): void
    {
        $this->connection->execute('DROP TABLE themes');

        $resolved = $this->resolver->resolveThemeDirectory();

        $this->assertSame($this->root . '/themes/fallback', $resolved);
    }

    public function testNothingResolvesReturnsNull(): void
    {
        rmdir($this->root . '/themes/starter');
        rmdir($this->root . '/themes/fallback');

        $this->assertNull($this->resolver->resolveThemeDirectory());
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
