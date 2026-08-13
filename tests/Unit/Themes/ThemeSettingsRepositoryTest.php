<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Themes\ThemeSettingsRepository;

final class ThemeSettingsRepositoryTest extends TestCase
{
    private Connection $connection;
    private ThemeSettingsRepository $repository;

    /** @var list<array{key: string, label: string, type: string, default: string, options: list<string>}> */
    private array $schema;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-theme-settings-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
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
            ],
        );
        $this->connection = new Connection($config);
        (new Migrator($this->connection, $config))->migrate();
        $this->repository = new ThemeSettingsRepository($this->connection);
        $this->schema = [
            ['key' => 'hero_title', 'label' => 'Hero title', 'type' => 'text', 'default' => 'Hello', 'options' => []],
            ['key' => 'show_sidebar', 'label' => 'Show sidebar', 'type' => 'boolean', 'default' => '1', 'options' => []],
            ['key' => 'accent', 'label' => 'Accent', 'type' => 'color', 'default' => '#abcdef', 'options' => []],
        ];
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testValuesForFallsBackToDefaultsWhenUnset(): void
    {
        $values = $this->repository->valuesFor('starter', $this->schema);

        $this->assertSame([
            'hero_title' => 'Hello',
            'show_sidebar' => '1',
            'accent' => '#abcdef',
        ], $values);
    }

    public function testValuesForExcludesDormantKeysAbsentFromSchema(): void
    {
        $this->connection->execute(
            'INSERT INTO theme_settings (theme_slug, setting_key, value, created_at, updated_at)
             VALUES (:slug, :key, :value, :ts, :ts)',
            ['slug' => 'starter', 'key' => 'old_extra', 'value' => 'gone', 'ts' => gmdate('Y-m-d H:i:s')],
        );

        $values = $this->repository->valuesFor('starter', $this->schema);

        $this->assertArrayNotHasKey('old_extra', $values);
        $this->assertCount(3, $values);
    }

    public function testSaveUpsertsAndReflectsOnNextRead(): void
    {
        $this->repository->save('starter', [
            'hero_title' => 'Welcome',
            'accent' => '#ff00aa',
        ]);

        $values = $this->repository->valuesFor('starter', $this->schema);

        $this->assertSame('Welcome', $values['hero_title']);
        $this->assertSame('#ff00aa', $values['accent']);
        $this->assertSame('1', $values['show_sidebar'], 'unspecified keys still fall back to defaults');
    }

    public function testSaveIsScopedPerSlug(): void
    {
        $this->repository->save('alpha', ['hero_title' => 'Alpha title']);
        $this->repository->save('beta', ['hero_title' => 'Beta title']);

        $this->assertSame('Alpha title', $this->repository->valuesFor('alpha', $this->schema)['hero_title']);
        $this->assertSame('Beta title', $this->repository->valuesFor('beta', $this->schema)['hero_title']);
    }

    public function testSavePersistsAcrossCallsAndUpdatesExisting(): void
    {
        $this->repository->save('starter', ['hero_title' => 'first']);
        $this->repository->save('starter', ['hero_title' => 'second']);

        $values = $this->repository->valuesFor('starter', $this->schema);
        $this->assertSame('second', $values['hero_title']);
    }

    public function testDormantValuesAreRetainedAcrossReads(): void
    {
        $this->repository->save('starter', ['hero_title' => 'still here']);
        $this->connection->execute(
            'INSERT INTO theme_settings (theme_slug, setting_key, value, created_at, updated_at)
             VALUES (:slug, :key, :value, :ts, :ts)',
            [
                'slug' => 'starter',
                'key' => 'orphaned',
                'value' => 'hidden',
                'ts' => gmdate('Y-m-d H:i:s'),
            ],
        );

        $values = $this->repository->valuesFor('starter', $this->schema);
        $this->assertArrayNotHasKey('orphaned', $values);

        $rehydrated = $this->schema;
        $rehydrated[] = ['key' => 'orphaned', 'label' => 'Orphaned', 'type' => 'text', 'default' => '', 'options' => []];
        $values = $this->repository->valuesFor('starter', $rehydrated);
        $this->assertSame('hidden', $values['orphaned']);
    }
}
