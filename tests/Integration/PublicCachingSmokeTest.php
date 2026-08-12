<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\UserRepository;
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
 * End-to-end smoke tests for Phase 4 against the project-level starter
 * theme: editing a cached entry reflects on the next request, and the
 * starter theme's stylesheet is reachable at /assets/site.css and the
 * page actually links to it.
 */
final class PublicCachingSmokeTest extends TestCase
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
        $this->projectRoot = sys_get_temp_dir() . '/stead-smoke-' . bin2hex(random_bytes(4));
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
        mkdir($themeDir . '/assets', 0775, true);
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

        $users->create('ada@example.com', 'Ada Lovelace', self::PASSWORD, true, true);
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

    public function testEditingAnEntryShowsTheChangeOnNextRequest(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ]);
        $entryId = $this->seedEntryWithBody($collectionId, 'hello', 'Hello', 'first body');

        $first = (string) $this->kernel->handle(Request::create('/posts/hello'))->getContent();
        $this->assertStringContainsString('first body', $first);

        $this->postUpdateWithBody($collectionId, $entryId, 'Hello', 'edited body');

        $second = (string) $this->kernel->handle(Request::create('/posts/hello'))->getContent();
        $this->assertStringContainsString('edited body', $second);
        $this->assertStringNotContainsString('first body', $second);
    }

    public function testPublicPageLinksAndLoadsTheStarterStylesheet(): void
    {
        $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $this->seedEntry($this->lastCollectionId(), 'hello', 'Hello');

        $page = (string) $this->kernel->handle(Request::create('/posts/hello'))->getContent();
        $this->assertStringContainsString('href="/assets/site.css"', $page);

        $cssResponse = $this->kernel->handle(Request::create('/assets/site.css'));
        $this->assertSame(200, $cssResponse->getStatusCode());
        $this->assertSame('text/css; charset=utf-8', $cssResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=', (string) $cssResponse->headers->get('Cache-Control'));
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

    private function lastCollectionId(): int
    {
        $row = $this->connection->fetchOne('SELECT id FROM collections ORDER BY id DESC LIMIT 1');
        return (int) ($row['id'] ?? 0);
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

    private function postUpdateWithBody(int $collectionId, int $entryId, string $title, string $body): void
    {
        $response = $this->kernel->handle(Request::create(
            '/admin/collections/posts/entries/' . $entryId . '/edit',
            'POST',
            [
                'fields' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ],
        ));
        $this->assertSame(303, $response->getStatusCode());
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
            "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<link rel=\"stylesheet\" href=\"/assets/site.css\">"
            . "<title>{% block title %}{{ collection.name|default('Stead') }}{% endblock %}</title></head>"
            . "<body class=\"theme-starter {% block body_class %}{% endblock %}\">"
            . "<p class=\"theme-mark\">Starter theme</p>"
            . "{% block body %}{% endblock %}"
            . "</body></html>\n",
        );
        file_put_contents(
            $dir . '/home.twig',
            "{% extends 'base.twig' %}\n{% block title %}Home{% endblock %}\n"
            . "{% block body %}<h1>Welcome</h1>"
            . "{% if collections is defined %}<ul>{% for c in collections %}<li><a href=\"/{{ c.slug }}\">{{ c.name }}</a></li>{% endfor %}</ul>{% endif %}"
            . "{% endblock %}\n",
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
        file_put_contents($dir . '/assets/site.css', "body { color: #222; }\n");
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
