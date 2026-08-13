<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Exception\SafeException;
use Stead\Themes\ThemeRepository;

final class ThemeRepositoryTest extends TestCase
{
    private Connection $connection;
    private ThemeRepository $repository;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-themes-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
        mkdir($tmp . '/config', 0775, true);
        mkdir($tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $tmp . '/database/migrations/' . basename($src));
        }
        $config = new Configuration(
            $tmp,
            'development',
            [
                'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
                'sessions' => ['name' => 'stead_themes'],
            ],
        );
        $this->connection = new Connection($config);
        (new Migrator($this->connection, $config))->migrate();
        $this->repository = new ThemeRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testSeededStarterIsActive(): void
    {
        $active = $this->repository->findActive();
        $this->assertNotNull($active);
        $this->assertSame('starter', $active->slug);
        $this->assertTrue($active->isActive);

        $bySlug = $this->repository->findBySlug('starter');
        $this->assertNotNull($bySlug);
        $this->assertSame($active->id, $bySlug->id);
    }

    public function testCreateThenListIncludesTheNewTheme(): void
    {
        $created = $this->repository->create('custom', 'Custom Theme', '1.0.0', 'Ada');

        $this->assertSame('custom', $created->slug);
        $this->assertSame('Custom Theme', $created->name);
        $this->assertSame('1.0.0', $created->version);
        $this->assertSame('Ada', $created->author);
        $this->assertFalse($created->isActive);

        $slugs = array_map(static fn ($theme) => $theme->slug, $this->repository->listAll());
        $this->assertContains('starter', $slugs);
        $this->assertContains('custom', $slugs);
    }

    public function testActivateMakesExactlyOneThemeActive(): void
    {
        $created = $this->repository->create('alt', 'Alt');
        $this->repository->activate($created->id);

        $active = $this->repository->findActive();
        $this->assertNotNull($active);
        $this->assertSame('alt', $active->slug);
        $this->assertTrue($active->isActive);

        $starter = $this->repository->findBySlug('starter');
        $this->assertNotNull($starter);
        $this->assertFalse($starter->isActive);

        $activeCount = 0;
        foreach ($this->repository->listAll() as $theme) {
            if ($theme->isActive) {
                $activeCount++;
            }
        }
        $this->assertSame(1, $activeCount);
    }

    public function testDeleteRejectsTheActiveTheme(): void
    {
        $active = $this->repository->findActive();
        $this->assertNotNull($active);

        $this->expectException(SafeException::class);
        $this->expectExceptionMessage('The active theme cannot be deleted.');
        $this->repository->delete($active->id);
    }

    public function testDeleteRemovesAnInactiveTheme(): void
    {
        $created = $this->repository->create('doomed', 'Doomed');
        $this->repository->delete($created->id);

        $this->assertNull($this->repository->findBySlug('doomed'));
    }
}
