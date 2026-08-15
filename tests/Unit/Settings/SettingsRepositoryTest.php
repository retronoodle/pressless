<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Stead\Settings\Settings;
use Stead\Settings\SettingsRepository;
use Stead\Database\Connection;
use Stead\Config\Configuration;
use Stead\Database\Migrator;

/**
 * Round-trips the single-row Settings through its repository against a
 * real SQLite schema. Covers the seed-defaults-on-missing-row behaviour
 * and the upsert-on-save contract.
 */
final class SettingsRepositoryTest extends TestCase
{
    private Configuration $config;
    private Connection $connection;
    private Migrator $migrator;
    private SettingsRepository $repository;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-settings-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/var/log', 0775, true);
        mkdir($tmp . '/config', 0775, true);
        mkdir($tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $tmp . '/database/migrations/' . basename($src));
        }
        $this->config = new Configuration(
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
                'sessions' => ['name' => 'stead_settings'],
            ],
        );
        $this->connection = new Connection($this->config);
        $this->migrator = new Migrator($this->connection, $this->config);
        $this->migrator->migrate();
        $this->repository = new SettingsRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testLoadAfterMigrationReturnsSeededRow(): void
    {
        $settings = $this->repository->load();

        $this->assertSame('', $settings->siteName);
        $this->assertSame('UTC', $settings->timezone);
        $this->assertSame('Y-m-d', $settings->dateFormat);
        $this->assertNull($settings->homepageType);
        $this->assertNull($settings->homepagePageId);
    }

    public function testSaveThenLoadRoundTripsValues(): void
    {
        $this->repository->save(new Settings('My Site', 'Europe/London', 'F j, Y'));

        $settings = $this->repository->load();
        $this->assertSame('My Site', $settings->siteName);
        $this->assertSame('Europe/London', $settings->timezone);
        $this->assertSame('F j, Y', $settings->dateFormat);
    }

    public function testSaveIsAnUpsertInPlace(): void
    {
        $this->repository->save(new Settings('First', 'UTC', 'Y-m-d'));
        $this->repository->save(new Settings('Second', 'America/New_York', 'd/m/Y'));

        $rows = $this->connection->fetchAll('SELECT id FROM settings');
        $this->assertCount(1, $rows, 'saving settings must not create duplicate rows.');

        $settings = $this->repository->load();
        $this->assertSame('Second', $settings->siteName);
        $this->assertSame('America/New_York', $settings->timezone);
        $this->assertSame('d/m/Y', $settings->dateFormat);
    }

    public function testHomepageOverrideRoundTripsAsStaticPageWithPageId(): void
    {
        $this->repository->save(new Settings(
            'Site',
            'UTC',
            'Y-m-d',
            Settings::HOMEPAGE_TYPE_STATIC_PAGE,
            42,
        ));

        $settings = $this->repository->load();
        $this->assertSame(Settings::HOMEPAGE_TYPE_STATIC_PAGE, $settings->homepageType);
        $this->assertSame(42, $settings->homepagePageId);
    }

    public function testHomepageOverrideRoundTripsAsNullWhenCleared(): void
    {
        $this->repository->save(new Settings(
            'Site',
            'UTC',
            'Y-m-d',
            Settings::HOMEPAGE_TYPE_STATIC_PAGE,
            7,
        ));
        $this->repository->save(new Settings('Site', 'UTC', 'Y-m-d', null, null));

        $settings = $this->repository->load();
        $this->assertNull($settings->homepageType);
        $this->assertNull($settings->homepagePageId);
    }

    public function testHomepagePageIdIsDroppedWhenTypeIsNotStaticPage(): void
    {
        $this->connection->execute(
            'UPDATE settings SET homepage_type = :type, homepage_page_id = :pid WHERE id = 1',
            ['type' => 'something_else', 'pid' => 5],
        );

        $settings = $this->repository->load();
        $this->assertNull(
            $settings->homepageType,
            'an unrecognised homepage_type is read back as null so clearing the override is unambiguous.',
        );
        $this->assertNull(
            $settings->homepagePageId,
            'a non-static-page type means homepage_page_id has no meaning and reads back as null.',
        );
    }

    public function testBlogOverrideRoundTripsWithCollectionId(): void
    {
        $this->repository->save(new Settings(
            'Site',
            'UTC',
            'Y-m-d',
            Settings::HOMEPAGE_TYPE_BLOG,
            null,
            7,
        ));

        $settings = $this->repository->load();
        $this->assertSame(Settings::HOMEPAGE_TYPE_BLOG, $settings->homepageType);
        $this->assertSame(7, $settings->homepageCollectionId);
        $this->assertNull(
            $settings->homepagePageId,
            'a blog override never carries a page id.',
        );
    }

    public function testBlogOverrideClearedRoundTripsAsNull(): void
    {
        $this->repository->save(new Settings(
            'Site',
            'UTC',
            'Y-m-d',
            Settings::HOMEPAGE_TYPE_BLOG,
            null,
            9,
        ));
        $this->repository->save(new Settings('Site', 'UTC', 'Y-m-d', null, null, null));

        $settings = $this->repository->load();
        $this->assertNull($settings->homepageType);
        $this->assertNull($settings->homepageCollectionId);
    }

    public function testHomepageCollectionIdIsDroppedWhenTypeIsNotBlog(): void
    {
        $this->connection->execute(
            'UPDATE settings SET homepage_type = :type, homepage_collection_id = :cid WHERE id = 1',
            ['type' => 'static_page', 'cid' => 11],
        );

        $settings = $this->repository->load();
        $this->assertSame(Settings::HOMEPAGE_TYPE_STATIC_PAGE, $settings->homepageType);
        $this->assertNull(
            $settings->homepageCollectionId,
            'a non-blog type means homepage_collection_id has no meaning and reads back as null.',
        );
    }

    public function testDefaultsExposeNullHomepageCollectionId(): void
    {
        $settings = $this->repository->load();
        $this->assertNull(
            $settings->homepageCollectionId,
            'a freshly migrated settings row has no blog override by default.',
        );
    }
}