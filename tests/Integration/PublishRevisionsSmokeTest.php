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
use Stead\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase 6 smoke tests for the draft / publish / unpublish / revisions flow.
 *
 * Covers the two end-to-end checks from the PRD: a publish → unpublish →
 * revert cycle (entries appear and disappear from the public site as their
 * status toggles, and `published_at` is preserved across unpublish), and
 * the retention limit pruning the oldest revisions after a save.
 */
final class PublishRevisionsSmokeTest extends TestCase
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
        $this->projectRoot = sys_get_temp_dir() . '/stead-phase6-' . bin2hex(random_bytes(4));
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
                'sessions' => ['name' => 'stead_phase6'],
                'content' => ['revision_retention_limit' => 3],
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
        $router = Routes::createWithStore($app, $this->store, new TwigRenderer($this->config));
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

    public function testPublishUnpublishAndRestoreRevisitPublicRoute(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello');

        // Initial public render with the seeded published entry.
        $first = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(200, $first->getStatusCode());
        $this->assertStringContainsString('Hello', (string) $first->getContent());

        // Publish/unpublish round-trip should toggle public visibility and
        // leave `published_at` intact across an unpublish.
        $this->dispatchAdmin(
            '/admin/collections/posts/entries/' . $entryId . '/unpublish',
            [],
        );
        $unpublished = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(404, $unpublished->getStatusCode(), 'unpublished entry must 404 on public route');

        $originalPublishedAt = (string) ($this->connection->fetchOne(
            'SELECT published_at FROM entries WHERE id = :id',
            ['id' => $entryId],
        )['published_at'] ?? '');

        $this->dispatchAdmin(
            '/admin/collections/posts/entries/' . $entryId . '/publish',
            [],
        );
        $republished = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(200, $republished->getStatusCode());
        $this->assertStringContainsString('Hello', (string) $republished->getContent());

        $newPublishedAt = (string) ($this->connection->fetchOne(
            'SELECT published_at FROM entries WHERE id = :id',
            ['id' => $entryId],
        )['published_at'] ?? '');
        $this->assertNotSame('', $newPublishedAt);
        $this->assertSame($originalPublishedAt, $newPublishedAt, 'republish must preserve the original published_at');
    }

    public function testRetainingOnlyTheMostRecentRevisionsAfterSave(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello', 'original body');

        // Five successive updates — first save is a resave that writes a
        // revision, and the configured retention limit is 3.
        for ($i = 1; $i <= 5; $i++) {
            $this->dispatchAdmin(
                '/admin/collections/posts/entries/' . $entryId . '/edit',
                [
                    'fields' => [
                        'title' => 'Hello',
                        'body' => 'edit ' . $i,
                    ],
                ],
            );
        }

        $count = (int) ($this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM revisions WHERE entry_id = :entry_id',
            ['entry_id' => $entryId],
        )['c']);
        $this->assertSame(3, $count, 'revisions must be pruned to the configured retention limit');

        // The oldest retained revision should still correspond to one of
        // the recent edits — never to the very first state.
        $oldest = $this->connection->fetchOne(
            'SELECT payload FROM revisions WHERE entry_id = :entry_id ORDER BY created_at ASC, id ASC LIMIT 1',
            ['entry_id' => $entryId],
        );
        $payload = json_decode((string) ($oldest['payload'] ?? '{}'), true);
        $oldestBody = $payload['values']['body'] ?? null;
        $this->assertContains(
            $oldestBody,
            ['edit 2', 'edit 3', 'edit 4'],
            'the oldest retained revision should be one of the recent edits (not the original state).',
        );

        $newest = $this->connection->fetchOne(
            'SELECT payload FROM revisions WHERE entry_id = :entry_id ORDER BY created_at DESC, id DESC LIMIT 1',
            ['entry_id' => $entryId],
        );
        $newestPayload = json_decode((string) ($newest['payload'] ?? '{}'), true);
        $this->assertSame('edit 4', $newestPayload['values']['body'] ?? null);
    }

    public function testRestoringARevisionRevertsFieldValuesAndSnapshotsPreRestore(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntry($collectionId, 'hello', 'Hello', 'original body');

        // Save twice so we have a known pre-restore revision.
        $this->dispatchAdmin(
            '/admin/collections/posts/entries/' . $entryId . '/edit',
            [
                'fields' => [
                    'title' => 'Hello',
                    'body' => 'first edit',
                ],
            ],
        );
        $this->dispatchAdmin(
            '/admin/collections/posts/entries/' . $entryId . '/edit',
            [
                'fields' => [
                    'title' => 'Hello',
                    'body' => 'second edit',
                ],
            ],
        );

        // Restore the most recent revision, which captured the pre-edit-2
        // state ('first edit').
        $latest = $this->connection->fetchOne(
            'SELECT id FROM revisions WHERE entry_id = :entry_id ORDER BY created_at DESC, id DESC LIMIT 1',
            ['entry_id' => $entryId],
        );
        $latestId = (int) $latest['id'];

        $this->dispatchAdmin(
            '/admin/collections/posts/entries/' . $entryId . '/revisions/' . $latestId . '/restore',
            [],
        );

        $body = (string) ($this->connection->fetchOne(
            'SELECT value_text AS v FROM entry_values WHERE entry_id = :entry_id AND field_key = :fk',
            ['entry_id' => $entryId, 'fk' => 'body'],
        )['v'] ?? '');
        $this->assertSame('first edit', $body);

        // The restore itself should have written a fresh pre-restore revision.
        $count = (int) ($this->connection->fetchOne(
            'SELECT COUNT(*) AS c FROM revisions WHERE entry_id = :entry_id',
            ['entry_id' => $entryId],
        )['c']);
        $this->assertSame(3, $count);
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

    private function seedEntry(int $collectionId, string $slug, string $title, string $body = ''): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO entries (collection_id, slug, title, status, published_at, created_at, updated_at)
             VALUES (:cid, :slug, :title, :status, :pa, :ts, :ts)',
            ['cid' => $collectionId, 'slug' => $slug, 'title' => $title, 'status' => 'published', 'pa' => $now, 'ts' => $now],
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

    /**
     * @param array<string, mixed> $parameters
     */
    private function dispatchAdmin(string $path, array $parameters = []): void
    {
        $response = $this->kernel->handle(Request::create($path, 'POST', $parameters));
        $this->assertContains(
            $response->getStatusCode(),
            [200, 303],
            sprintf('Unexpected admin response %d for %s', $response->getStatusCode(), $path),
        );
    }

    private function installDefaultTemplates(): void
    {
        $layoutDir = $this->templatesDir . '/layout';
        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0775, true);
        }
        file_put_contents(
            $layoutDir . '/base.twig',
            "<!doctype html><html><head></head><body>{% block body %}{% endblock %}</body></html>\n",
        );
        file_put_contents(
            $this->templatesDir . '/login.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}sign in{% endblock %}\n",
        );
        file_put_contents(
            $this->templatesDir . '/admin.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}admin{% endblock %}\n",
        );
    }

    private function installStarterTheme(string $dir): void
    {
        file_put_contents(
            $dir . '/base.twig',
            "<!doctype html><html lang=\"en\"><head></head>"
            . "<body class=\"theme-starter {% block body_class %}{% endblock %}\">"
            . "<p class=\"theme-mark\">Starter theme</p>"
            . "{% block body %}{% endblock %}"
            . "</body></html>\n",
        );
        file_put_contents(
            $dir . '/home.twig',
            "{% extends 'base.twig' %}\n{% block body %}<h1>Welcome</h1>{% endblock %}\n",
        );
        file_put_contents(
            $dir . '/collection.twig',
            "{% extends 'base.twig' %}\n{% block title %}{{ collection.name }}{% endblock %}\n"
            . "{% block body %}<h1>{{ collection.name }}</h1>"
            . "<ul class=\"entries\">{% for entry in entries %}<li><a href=\"/{{ collection.slug }}/{{ entry.slug }}\">{{ entry.value('title') }}</a></li>{% endfor %}</ul>"
            . "{% endblock %}\n",
        );
        file_put_contents(
            $dir . '/entry.twig',
            "{% extends 'base.twig' %}\n{% block title %}{{ entry.value('title') }}{% endblock %}\n"
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