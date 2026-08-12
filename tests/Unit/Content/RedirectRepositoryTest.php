<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Content\Redirect;
use Stead\Content\RedirectRepository;
use Stead\Database\Connection;
use Stead\Database\Migrator;

/**
 * Round-trips Redirects through their repository against a real SQLite
 * schema. Covers create, find-by-old-path, upsert-on-duplicate-old-path,
 * delete, and listing.
 */
final class RedirectRepositoryTest extends TestCase
{
    private Connection $connection;
    private Migrator $migrator;
    private RedirectRepository $repository;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-redirects-' . bin2hex(random_bytes(4));
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
                'sessions' => ['name' => 'stead_redirects'],
            ],
        );
        $this->connection = new Connection($config);
        $this->migrator = new Migrator($this->connection, $config);
        $this->migrator->migrate();
        $this->repository = new RedirectRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testUpsertCreatesRow(): void
    {
        $this->repository->upsert('/posts/old', '/posts/new');

        $row = $this->connection->fetchOne(
            'SELECT old_path, new_path FROM redirects WHERE old_path = :old_path',
            ['old_path' => '/posts/old'],
        );
        $this->assertNotNull($row);
        $this->assertSame('/posts/new', $row['new_path']);
    }

    public function testFindByOldPathReturnsTheStoredRedirect(): void
    {
        $this->repository->upsert('/posts/old', '/posts/new');

        $redirect = $this->repository->findByOldPath('/posts/old');

        $this->assertInstanceOf(Redirect::class, $redirect);
        $this->assertSame('/posts/old', $redirect->oldPath());
        $this->assertSame('/posts/new', $redirect->newPath());
        $this->assertGreaterThan(0, $redirect->id());
    }

    public function testFindByOldPathReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository->findByOldPath('/posts/missing'));
    }

    public function testUpsertReplacesExistingNewPathForSameOldPath(): void
    {
        $this->repository->upsert('/posts/old', '/posts/first');
        $this->repository->upsert('/posts/old', '/posts/second');

        $rows = $this->connection->fetchAll('SELECT new_path FROM redirects');
        $this->assertCount(1, $rows, 'upserting the same old_path must not duplicate rows.');
        $this->assertSame('/posts/second', $rows[0]['new_path']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $this->repository->upsert('/posts/old', '/posts/new');
        $redirect = $this->repository->findByOldPath('/posts/old');
        $this->assertNotNull($redirect);

        $this->repository->delete($redirect->id());

        $this->assertNull($this->repository->findByOldPath('/posts/old'));
    }

    public function testAllListsAllRedirectsInOrder(): void
    {
        $this->repository->upsert('/a/old', '/a/new');
        $this->repository->upsert('/b/old', '/b/new');

        $redirects = $this->repository->all();

        $this->assertCount(2, $redirects);
        $oldPaths = array_map(static fn(Redirect $r) => $r->oldPath(), $redirects);
        $this->assertSame(['/a/old', '/b/old'], $oldPaths);
    }
}