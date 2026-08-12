<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Stead\Auth\AuthorizationService;
use Stead\Auth\PermissionRepository;
use Stead\Auth\User;
use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\Entry;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Config\Configuration;

final class AuthorizationServiceTest extends TestCase
{
    private string $projectRoot;
    private string $dbPath;
    private Connection $connection;
    private Configuration $config;
    private CollectionRepository $collections;
    private PermissionRepository $permissions;
    private AuthorizationService $authorization;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-authz-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
        }
        $this->dbPath = $this->projectRoot . '/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'development',
            [
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => ['migrations' => 'database/migrations'],
            ],
        );

        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $this->collections = new CollectionRepository($this->connection);
        $this->permissions = new PermissionRepository($this->connection);
        $this->authorization = new AuthorizationService($this->collections, $this->permissions);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/database",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
    }

    public function testAdminBypassesAllChecks(): void
    {
        $admin = $this->makeUser(1, User::ROLE_ADMIN);
        $this->assertTrue($this->authorization->isAdmin($admin));
        $this->assertTrue($this->authorization->can($admin, 'missing', 'view'));
        $this->assertTrue($this->authorization->canEntry($admin, 'missing', 'edit', $this->makeEntry(0)));
    }

    public function testNonAdminReturnsFalseForUnknownCollection(): void
    {
        $editor = $this->makeUser(2, User::ROLE_EDITOR);
        $this->assertFalse($this->authorization->can($editor, 'nothing-here', 'view'));
    }

    public function testEditorGrantedActionsAllowAccess(): void
    {
        $editor = $this->makeUser(2, User::ROLE_EDITOR);
        $posts = $this->createCollection('posts', 'Posts');
        $this->permissions->setActions($editor->roleId, $posts->id(), [
            PermissionRepository::ACTION_VIEW,
            PermissionRepository::ACTION_EDIT,
        ]);

        $this->assertTrue($this->authorization->can($editor, 'posts', 'view'));
        $this->assertTrue($this->authorization->can($editor, 'posts', 'edit'));
        $this->assertFalse($this->authorization->can($editor, 'posts', 'delete'));
        $this->assertFalse($this->authorization->can($editor, 'pages', 'view'));
    }

    public function testAuthorEntryOwnershipScoping(): void
    {
        $author = $this->makeUser(3, User::ROLE_AUTHOR);
        $posts = $this->createCollection('posts', 'Posts');
        $this->permissions->setActions($author->roleId, $posts->id(), [
            PermissionRepository::ACTION_VIEW,
            PermissionRepository::ACTION_EDIT,
            PermissionRepository::ACTION_DELETE,
            PermissionRepository::ACTION_CREATE,
        ]);

        $own = $this->makeEntry($posts->id(), authorId: $author->id);
        $other = $this->makeEntry($posts->id(), authorId: 999);

        $this->assertTrue($this->authorization->canEntry($author, 'posts', 'edit', $own));
        $this->assertFalse($this->authorization->canEntry($author, 'posts', 'edit', $other));
        $this->assertFalse($this->authorization->canEntry($author, 'posts', 'delete', $other));

        $this->assertTrue($this->authorization->canEntry($author, 'posts', 'view', $other));
        $this->assertTrue($this->authorization->canEntry($author, 'posts', 'create', $own));
    }

    public function testEditorNotOwnershipScoped(): void
    {
        $editor = $this->makeUser(2, User::ROLE_EDITOR);
        $posts = $this->createCollection('posts', 'Posts');
        $this->permissions->setActions($editor->roleId, $posts->id(), [
            PermissionRepository::ACTION_EDIT,
        ]);

        $other = $this->makeEntry($posts->id(), authorId: 999);
        $this->assertTrue($this->authorization->canEntry($editor, 'posts', 'edit', $other));
    }

    public function testUnauthenticatedUserDenied(): void
    {
        $this->assertFalse($this->authorization->isAdmin(null));
        $this->assertFalse($this->authorization->can(null, 'posts', 'view'));
        $this->assertFalse($this->authorization->canEntry(null, 'posts', 'view', $this->makeEntry(0)));
        $this->assertSame([], $this->authorization->grantedCollectionSlugs(null));
    }

    public function testGrantedCollectionSlugsReturnsScopedSets(): void
    {
        $editor = $this->makeUser(2, User::ROLE_EDITOR);
        $posts = $this->createCollection('posts', 'Posts');
        $pages = $this->createCollection('pages', 'Pages');
        $this->permissions->setActions($editor->roleId, $posts->id(), [
            PermissionRepository::ACTION_VIEW,
            PermissionRepository::ACTION_EDIT,
        ]);
        $this->permissions->setActions($editor->roleId, $pages->id(), [
            PermissionRepository::ACTION_VIEW,
        ]);

        $this->assertSame(
            ['pages', 'posts'],
            $this->authorization->grantedCollectionSlugs($editor, PermissionRepository::ACTION_VIEW),
        );
        $this->assertSame(
            ['posts'],
            $this->authorization->grantedCollectionSlugs($editor, PermissionRepository::ACTION_EDIT),
        );
    }

    public function testAdminSeesAllCollectionSlugs(): void
    {
        $admin = $this->makeUser(1, User::ROLE_ADMIN);
        $this->createCollection('posts', 'Posts');
        $this->createCollection('pages', 'Pages');

        $this->assertSame(
            ['pages', 'posts'],
            $this->authorization->grantedCollectionSlugs($admin, PermissionRepository::ACTION_VIEW),
        );
    }

    public function testAuthorMissingAuthorIdOnEntryDenied(): void
    {
        $author = $this->makeUser(3, User::ROLE_AUTHOR);
        $posts = $this->createCollection('posts', 'Posts');
        $this->permissions->setActions($author->roleId, $posts->id(), [
            PermissionRepository::ACTION_EDIT,
        ]);

        $orphan = $this->makeEntry($posts->id(), authorId: null);
        $this->assertFalse($this->authorization->canEntry($author, 'posts', 'edit', $orphan));
    }

    private function makeUser(int $id, string $roleName): User
    {
        $row = $this->connection->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => $roleName]);
        $roleId = (int) ($row['id'] ?? 0);
        return new User($id, 'u@example.com', 'User', 'hash', true, $roleId, $roleName);
    }

    private function createCollection(string $slug, string $name): Collection
    {
        return $this->collections->save(new Collection(0, $slug, $name, ['fields' => []]));
    }

    private function makeEntry(int $collectionId, ?int $authorId = null): Entry
    {
        return new Entry(1, $collectionId, 'sample', [], Entry::STATUS_DRAFT, null, $authorId);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
