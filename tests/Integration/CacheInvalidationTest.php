<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\UserRepository;
use Stead\Auth\User;
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
 * End-to-end coverage for cache invalidation when entries are created,
 * updated, or deleted. Asserts that the cached HTML for a page changes
 * after a mutation, and that mutations in collection A do not invalidate
 * cached pages for collection B.
 */
final class CacheInvalidationTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private Connection $connection;
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-invalidate-' . bin2hex(random_bytes(4));
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
                    'theme' => 'themes',
                ],
                'theme' => ['active' => 'starter'],
                'sessions' => ['name' => 'stead_session'],
            ],
        );

        $this->templatesDir = $this->projectRoot . '/templates';
        mkdir($this->templatesDir, 0775, true);
        $this->installDefaultTemplates();

        $themeDir = $this->projectRoot . '/themes/starter';
        mkdir($themeDir, 0775, true);
        $this->installStarterTheme($themeDir);

        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $auth = new AuthenticationService($users, $sessions, $hasher, $this->store, 3600);

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, TestRenderer::twig($this->config, $this->connection));
        $this->kernel = new Kernel($app, $router);

        $users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $auth->attempt('ada@example.com', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/themes",
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

    public function testUpdatingAnEntryInvalidatesItsPageAndCollectionListing(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntryWithBody($collectionId, 'hello', 'Hello', 'first body');

        $first = (string) $this->kernel->handle(Request::create('/posts/hello'))->getContent();
        $this->assertStringContainsString('Hello', $first);

        // Update the body — slug source (title) is unchanged, so the entry
        // remains at /posts/hello but the page must re-render.
        $this->postUpdateWithBody($collectionId, $entryId, 'Hello', 'updated body');
        $secondResponse = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(200, $secondResponse->getStatusCode());
        $second = (string) $secondResponse->getContent();
        $this->assertStringContainsString('updated body', $second);

        $listing = (string) $this->kernel->handle(Request::create('/posts'))->getContent();
        $this->assertStringContainsString('Hello', $listing);
    }

    public function testCreatingAnEntryInvalidatesCollectionListingAndBumpsVersion(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $this->seedEntry($collectionId, 'old', 'Old');
        $this->kernel->handle(Request::create('/posts'));
        $versionFile = "{$this->projectRoot}/var/cache/public/versions/{$collectionId}";

        $this->postCreate($collectionId, 'first', 'First');

        $this->assertFileExists($versionFile);
        $this->assertGreaterThanOrEqual('1', (string) @file_get_contents($versionFile));
        $listing = (string) $this->kernel->handle(Request::create('/posts'))->getContent();
        $this->assertStringContainsString('First', $listing);
    }

    public function testDeletingAnEntryInvalidatesListingAndReturns404ForDeletedEntry(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello');

        $this->kernel->handle(Request::create('/posts'));
        $this->kernel->handle(Request::create('/posts/hello'));

        $this->postDelete($collectionId, $entryId);

        $listing = (string) $this->kernel->handle(Request::create('/posts'))->getContent();
        $this->assertStringNotContainsString('Hello', $listing);

        $deletedResponse = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(404, $deletedResponse->getStatusCode());
    }

    public function testMutationInCollectionADoesNotInvalidateCollectionB(): void
    {
        $a = $this->seedCollection('a', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $b = $this->seedCollection('b', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $this->seedEntry($a, 'a1', 'A1');
        $this->seedEntry($b, 'b1', 'B1');

        $bFirst = (string) $this->kernel->handle(Request::create('/b/b1'))->getContent();
        $this->assertStringContainsString('B1', $bFirst);

        // Mutate A — collection B's cached page must still hit cache. We
        // verify by mutating B's underlying row without bumping its version;
        // a cache hit returns the original HTML.
        $aEntryId = (int) $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :c AND slug = :s',
            ['c' => $a, 's' => 'a1'],
        )['id'];
        $this->postUpdate($a, $aEntryId, 'A1-changed');

        $this->connection->execute(
            'UPDATE entries SET title = :t WHERE slug = :s',
            ['t' => 'B1-mutated', 's' => 'b1'],
        );

        $bSecond = (string) $this->kernel->handle(Request::create('/b/b1'))->getContent();
        $this->assertSame($bFirst, $bSecond, 'collection B page should still be served from cache');
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function seedCollection(string $slug, array $fields): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            [
                'slug' => $slug,
                'name' => ucfirst($slug),
                'schema' => json_encode(['fields' => $fields]),
                'ts' => $now,
            ],
        );
        return (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = :slug', ['slug' => $slug])['id'];
    }

    private function seedEntry(int $collectionId, string $slug, string $title): int
    {
        return $this->seedEntryWithBody($collectionId, $slug, $title, '');
    }

    private function seedEntryWithBody(int $collectionId, string $slug, string $title, string $body): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, created_at, updated_at)
             VALUES (:cid, :slug, :title, :status, :ts, :ts)',
            ['cid' => $collectionId, 'slug' => $slug, 'title' => $title, 'status' => 'published', 'ts' => $now],
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

    private function postCreate(int $collectionId, string $slug, string $title): void
    {
        $this->dispatchAdmin('/admin/collections/' . $this->slugFor($collectionId) . '/entries/new', [
            'fields' => [
                'title' => $title,
            ],
        ]);
        $row = $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :cid AND slug = :slug',
            ['cid' => $collectionId, 'slug' => $slug],
        );
        if ($row === null) {
            return;
        }
        $entryId = (int) $row['id'];
        $this->dispatchAdmin(
            '/admin/collections/' . $this->slugFor($collectionId) . '/entries/' . $entryId . '/publish',
            [],
        );
    }

    private function postUpdate(int $collectionId, int $entryId, string $title): void
    {
        $this->dispatchAdmin(
            '/admin/collections/' . $this->slugFor($collectionId) . '/entries/' . $entryId . '/edit',
            [
                'fields' => [
                    'title' => $title,
                ],
            ],
        );
    }

    private function postUpdateWithBody(int $collectionId, int $entryId, string $title, string $body): void
    {
        $this->dispatchAdmin(
            '/admin/collections/' . $this->slugFor($collectionId) . '/entries/' . $entryId . '/edit',
            [
                'fields' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ],
        );
    }

    private function postDelete(int $collectionId, int $entryId): void
    {
        $this->dispatchAdmin(
            '/admin/collections/' . $this->slugFor($collectionId) . '/entries/' . $entryId . '/delete',
            [],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dispatchAdmin(string $path, array $parameters): void
    {
        $response = $this->kernel->handle(Request::create($path, 'POST', $parameters));
        // 303 (See Other) on success, 200 on validation re-render.
        $this->assertContains(
            $response->getStatusCode(),
            [200, 303],
            sprintf('Unexpected admin response %d for %s', $response->getStatusCode(), $path),
        );
    }

    private function slugFor(int $collectionId): string
    {
        $row = $this->connection->fetchOne('SELECT slug FROM collections WHERE id = :id', ['id' => $collectionId]);
        return (string) ($row['slug'] ?? '');
    }

    private function installDefaultTemplates(): void
    {
        $layoutDir = $this->templatesDir . '/layout';
        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0775, true);
        }
        file_put_contents(
            $layoutDir . '/base.twig',
            "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<title>{% block title %}Stead{% endblock %}</title></head>"
            . "<body class=\"{% block body_class %}default{% endblock %}\">"
            . "{% block body %}{% endblock %}</body></html>\n",
        );
        file_put_contents(
            $this->templatesDir . '/login.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}<h1>Sign in</h1>{% endblock %}\n",
        );
        file_put_contents(
            $this->templatesDir . '/admin.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}<h1>Admin</h1>{% endblock %}\n",
        );
    }

    private function installStarterTheme(string $dir): void
    {
        file_put_contents(
            $dir . '/base.twig',
            "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<title>{% block title %}{{ collection.name|default('Stead') }}{% endblock %}</title></head>"
            . "<body class=\"theme-starter {% block body_class %}{% endblock %}\">"
            . "<p class=\"theme-mark\">Starter theme</p>"
            . "{% block body %}{% endblock %}"
            . "</body></html>\n",
        );
        file_put_contents(
            $dir . '/home.twig',
            "{% extends 'base.twig' %}\n"
            . "{% block title %}Home{% endblock %}\n"
            . "{% block body %}<h1>Welcome</h1>"
            . "{% if collections is defined %}<ul>{% for c in collections %}<li><a href=\"/{{ c.slug }}\">{{ c.name }}</a></li>{% endfor %}</ul>{% endif %}"
            . "{% endblock %}\n",
        );
        file_put_contents(
            $dir . '/collection.twig',
            "{% extends 'base.twig' %}\n"
            . "{% block title %}{{ collection.name }}{% endblock %}\n"
            . "{% block body %}<h1>{{ collection.name }}</h1>"
            . "<ul class=\"entries\">"
            . "{% for entry in entries %}<li><a href=\"/{{ collection.slug }}/{{ entry.slug }}\">{{ entry.value('title') }}</a></li>{% endfor %}"
            . "</ul>{% endblock %}\n",
        );
        file_put_contents(
            $dir . '/entry.twig',
            "{% extends 'base.twig' %}\n"
            . "{% block title %}{{ entry.value('title') }}{% endblock %}\n"
            . "{% block body %}<article class=\"entry\">"
            . "<h1>{{ entry.value('title') }}</h1>"
            . "{% set body = entry.value('body') %}{% if body %}<div class=\"body\">{{ body }}</div>{% endif %}"
            . "</article>{% endblock %}\n",
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
