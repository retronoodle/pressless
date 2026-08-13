<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\PermissionRepository;
use Stead\Auth\SessionRepository;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\Tests\Support\TestRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase 7 smoke tests for the roles-and-permissions capabilities.
 *
 * Covers the two acceptance scenarios from the proposal: an editor who
 * can manage a single granted collection and is denied everywhere else,
 * and an author who is additionally ownership-scoped (their own entries
 * only).
 */
final class RolesAndPermissionsSmokeTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Connection $connection;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $auth;
    private UserRepository $users;
    private PermissionRepository $permissions;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-phase7-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $file) {
            copy($file, $this->projectRoot . '/database/migrations/' . basename($file));
        }
        $this->dbPath = $this->projectRoot . '/var/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'production',
            [
                'app' => ['debug' => false],
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
                'sessions' => ['name' => 'stead_phase7'],
            ],
        );

        $this->installTemplates();
        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $this->users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $this->auth = new AuthenticationService($this->users, $sessions, $hasher, $this->store, 3600);
        $this->permissions = new PermissionRepository($this->connection);

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, TestRenderer::twig($this->config, $this->connection));
        $this->kernel = new Kernel($app, $router);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/templates",
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/var/cache",
            "{$this->projectRoot}/var/log",
            "{$this->projectRoot}/var",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
    }

    public function testEditorGrantedOnlyPostsCanManagePostsAndIsDeniedElsewhere(): void
    {
        $admin = $this->users->create('admin@example.com', 'Admin', self::PASSWORD, User::ROLE_ADMIN, true);
        $editor = $this->users->create('editor@example.com', 'Editor', self::PASSWORD, User::ROLE_EDITOR, true);

        $postsId = $this->seedCollection('posts', 'Posts');
        $pagesId = $this->seedCollection('pages', 'Pages');

        $this->permissions->setActions($editor->roleId, $postsId, [
            PermissionRepository::ACTION_VIEW,
            PermissionRepository::ACTION_CREATE,
            PermissionRepository::ACTION_EDIT,
            PermissionRepository::ACTION_PUBLISH,
        ]);

        $this->login($editor);

        // Posts is reachable for view, create, edit, publish.
        $this->assertSame(200, $this->get('/admin/collections/posts')->getStatusCode());
        $this->assertSame(200, $this->get('/admin/collections/posts/entries/new')->getStatusCode());
        $this->assertSame(303, $this->post('/admin/collections/posts/entries/new', [
            'fields' => ['title' => 'Editor entry', 'body' => 'body'],
        ])->getStatusCode());

        // Pages is denied.
        $this->assertSame(403, $this->get('/admin/collections/pages')->getStatusCode());
        $this->assertSame(403, $this->get('/admin/collections/pages/entries/new')->getStatusCode());

        // Editor cannot manage schema, even on Posts.
        $this->assertSame(403, $this->get('/admin/collections/posts/edit')->getStatusCode());
        $this->assertSame(403, $this->get('/admin/collections/new')->getStatusCode());

        // Editor cannot access user management.
        $this->assertSame(403, $this->get('/admin/users')->getStatusCode());
        $this->assertSame(403, $this->get('/admin/permissions')->getStatusCode());

        // Sidebar / collections list filters to granted slugs.
        $index = $this->get('/admin/collections');
        $this->assertSame(200, $index->getStatusCode());
        $body = (string) $index->getContent();
        $this->assertStringContainsString('posts', $body);
        $this->assertStringNotContainsString('>Pages<', $body);

        // Re-login as admin and verify nothing was locked out.
        $this->login($admin);
        $this->assertSame(200, $this->get('/admin/collections/pages')->getStatusCode());
        $this->assertSame(200, $this->get('/admin/users')->getStatusCode());
    }

    public function testAuthorIsOwnershipScopedToOwnEntries(): void
    {
        $admin = $this->users->create('admin@example.com', 'Admin', self::PASSWORD, User::ROLE_ADMIN, true);
        $author = $this->users->create('author@example.com', 'Author', self::PASSWORD, User::ROLE_AUTHOR, true);
        $other = $this->users->create('other@example.com', 'Other', self::PASSWORD, User::ROLE_AUTHOR, true);

        $postsId = $this->seedCollection('posts', 'Posts');

        $this->permissions->setActions($author->roleId, $postsId, [
            PermissionRepository::ACTION_VIEW,
            PermissionRepository::ACTION_CREATE,
            PermissionRepository::ACTION_EDIT,
        ]);

        $ownEntry = $this->seedEntry($postsId, 'mine', 'Mine', '', $author->id);
        $otherEntry = $this->seedEntry($postsId, 'theirs', 'Theirs', '', $other->id);

        $this->login($author);

        // Author can list — both entries are visible (view is not ownership-scoped).
        $list = $this->get('/admin/collections/posts');
        $this->assertSame(200, $list->getStatusCode());
        $body = (string) $list->getContent();
        $this->assertStringContainsString('mine', $body);
        $this->assertStringContainsString('theirs', $body);

        // Editing an entry the author did not write is denied.
        $this->assertSame(403, $this->get('/admin/collections/posts/entries/' . $otherEntry . '/edit')->getStatusCode());

        // Author can edit their own entry.
        $this->assertSame(200, $this->get('/admin/collections/posts/entries/' . $ownEntry . '/edit')->getStatusCode());

        // Updating the other's entry is rejected.
        $this->assertSame(403, $this->post('/admin/collections/posts/entries/' . $otherEntry . '/edit', [
            'fields' => ['title' => 'Hijacked', 'body' => 'nope'],
        ])->getStatusCode());

        // The other's entry is unchanged.
        $row = $this->connection->fetchOne(
            'SELECT title FROM entries WHERE id = :id',
            ['id' => $otherEntry],
        );
        $this->assertSame('Theirs', $row['title'] ?? null);

        // Admin can still see and edit both.
        $this->login($admin);
        $this->assertSame(200, $this->get('/admin/collections/posts/entries/' . $otherEntry . '/edit')->getStatusCode());
    }

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create($path));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function post(string $path, array $parameters): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create($path, 'POST', $parameters));
    }

    private function login(User $user): void
    {
        $this->store->start();
        $this->auth->attempt($user->email, self::PASSWORD);
    }

    private function seedCollection(string $slug, string $name): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            [
                'slug' => $slug,
                'name' => $name,
                'schema' => json_encode([
                    'fields' => [
                        ['key' => 'title', 'type' => 'text', 'required' => true],
                        ['key' => 'body', 'type' => 'richtext'],
                    ],
                ]),
                'ts' => $now,
            ],
        );
        return (int) $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => $slug],
        )['id'];
    }

    private function seedEntry(int $collectionId, string $slug, string $title, string $body, ?int $authorId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, author_id, created_at, updated_at)
             VALUES (:cid, :slug, :title, :status, :aid, :ts, :ts)',
            [
                'cid' => $collectionId,
                'slug' => $slug,
                'title' => $title,
                'status' => 'draft',
                'aid' => $authorId,
                'ts' => $now,
            ],
        );
        $entryId = (int) $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :cid AND slug = :slug',
            ['cid' => $collectionId, 'slug' => $slug],
        )['id'];
        $this->connection->execute(
            'INSERT INTO entry_values (entry_id, field_key, field_type, value, value_text, created_at, updated_at)
             VALUES (:eid, :key, :type, :value, :value_text, :ts, :ts)',
            [
                'eid' => $entryId,
                'key' => 'title',
                'type' => 'text',
                'value' => $title,
                'value_text' => $title,
                'ts' => $now,
            ],
        );
        if ($body !== '') {
            $this->connection->execute(
                'INSERT INTO entry_values (entry_id, field_key, field_type, value, value_text, created_at, updated_at)
                 VALUES (:eid, :key, :type, :value, :value_text, :ts, :ts)',
                [
                    'eid' => $entryId,
                    'key' => 'body',
                    'type' => 'richtext',
                    'value' => $body,
                    'value_text' => $body,
                    'ts' => $now,
                ],
            );
        }
        return $entryId;
    }

    private function installTemplates(): void
    {
        $templatesDir = $this->projectRoot . '/templates';
        foreach (['layout', 'admin', 'admin/collections', 'admin/entries', 'admin/users', 'admin/permissions'] as $sub) {
            mkdir($templatesDir . '/' . $sub, 0775, true);
        }
        file_put_contents(
            $templatesDir . '/layout/base.twig',
            "<!doctype html><html><head></head><body>{% block body %}{% endblock %}</body></html>\n",
        );
        file_put_contents(
            $templatesDir . '/login.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}sign in{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}admin{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/collections/index.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}"
            . "{% for c in collections %}<a href=\"/admin/collections/{{ c.slug|e }}\">{{ c.name|e }}</a>{% endfor %}"
            . "{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/entries/index.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}"
            . "{% for e in entries %}<code>{{ e.slug|e }}</code>{% endfor %}"
            . "{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/entries/form.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}form{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/users/index.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}users{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/permissions/index.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}permissions{% endblock %}\n",
        );
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
