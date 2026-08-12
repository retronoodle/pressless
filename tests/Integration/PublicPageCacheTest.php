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
 * End-to-end coverage for the public page cache: the first request renders
 * and writes a cached file, a repeat request is served from that file (the
 * renderer / DB are not consulted again), and 404s never touch the cache.
 */
final class PublicPageCacheTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private Connection $connection;
    private string $templatesDir;
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-cache-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $file) {
            copy($file, $this->projectRoot . '/database/migrations/' . basename($file));
        }
        $this->dbPath = $this->projectRoot . '/var/stead.sqlite';
        $this->pagesDir = $this->projectRoot . '/var/cache/public/pages';

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

    public function testFirstRequestRendersAndSecondIsServedFromCache(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $this->seedEntry($collectionId, 'hello', 'Hello');

        $first = $this->kernel->handle(Request::create('/posts'));
        $this->assertSame(200, $first->getStatusCode());
        $this->assertDirectoryExists($this->pagesDir);
        $cachedFiles = glob($this->pagesDir . '/*.html') ?: [];
        $this->assertNotEmpty($cachedFiles, 'first request must write a cache file');

        // Wipe the collection listing from the DB so a re-render would 404;
        // a cache hit returns the prior HTML verbatim.
        $this->connection->execute('DELETE FROM entries WHERE collection_id = :id', ['id' => $collectionId]);

        $second = $this->kernel->handle(Request::create('/posts'));
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame((string) $first->getContent(), (string) $second->getContent());
    }

    public function testUnknownCollectionReturns404AndDoesNotWriteACacheFile(): void
    {
        $response = $this->kernel->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], glob($this->pagesDir . '/*.html') ?: []);
    }

    public function testEntryRouteAlsoCachesAcrossRequests(): void
    {
        $collectionId = $this->seedCollection('posts', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);
        $this->seedEntry($collectionId, 'hello', 'Hello');

        $first = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(200, $first->getStatusCode());
        $this->assertNotEmpty(glob($this->pagesDir . '/*.html') ?: [], 'first request must write a cache file');

        // Mutate the entry's underlying row so a fresh render would change;
        // the cached HTML must still be served because the version didn't bump.
        $this->connection->execute(
            'UPDATE entries SET title = :t WHERE slug = :s',
            ['t' => 'Changed', 's' => 'hello'],
        );

        $second = $this->kernel->handle(Request::create('/posts/hello'));
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame((string) $first->getContent(), (string) $second->getContent());
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
        return $entryId;
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
