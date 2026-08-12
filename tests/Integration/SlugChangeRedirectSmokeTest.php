<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Content\Entry;
use Stead\Content\EntryRepository;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\FieldType\TextFieldType;
use Stead\Content\RedirectRepository;
use Stead\Content\SlugGenerator;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase 13 end-to-end coverage for slug-change redirects.
 *
 * Boots the full router so the public-route 301 lookup is exercised against
 * a real SQLite schema. Covers the auto-create-on-slug-change flow, the
 * brand-new-entry-needs-no-redirect invariant, and the live-entry-wins
 * invariant from the public-rendering spec.
 */
final class SlugChangeRedirectSmokeTest extends TestCase
{
    private const PASSWORD = 'admin-password-1';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private Connection $connection;
    private EntryRepository $entries;
    private RedirectRepository $redirects;
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-redirects-smoke-' . bin2hex(random_bytes(4));
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
                'sessions' => ['name' => 'stead_redirect_smoke'],
            ],
        );

        $this->templatesDir = $this->projectRoot . '/templates';
        mkdir($this->templatesDir, 0775, true);
        $this->installDefaultTemplates();
        $this->installStarterTheme($this->projectRoot . '/themes/starter');

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

        $fieldTypes = new FieldTypeRegistry([new TextFieldType()]);
        $slugs = new SlugGenerator($this->connection);
        $this->redirects = new RedirectRepository($this->connection);
        $this->entries = new EntryRepository($this->connection, $fieldTypes, $slugs, null, null, $this->redirects);

        $admin = $users->create('admin@example.com', 'Admin', self::PASSWORD, User::ROLE_ADMIN, true);
        $this->store->start();
        $auth->attempt($admin->email, self::PASSWORD);
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

    public function testRenamingASlugIssuesA301FromTheOldUrlAndRendersTheNewOne(): void
    {
        $collection = $this->seedCollection();
        $saved = $this->entries->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'Original Title'],
        );
        $this->entries->publish($saved->id());

        // The new URL serves the entry immediately after publish.
        $first = $this->kernel->handle(Request::create('/posts/original-title'));
        $this->assertSame(200, $first->getStatusCode());

        // Rename via save — slug source changes, so a redirect is created.
        $this->entries->save(
            $saved,
            $collection,
            ['title' => 'Renamed Title'],
        );

        $oldResponse = $this->kernel->handle(Request::create('/posts/original-title'));
        $this->assertSame(
            301,
            $oldResponse->getStatusCode(),
            'Body: ' . (string) $oldResponse->getContent(),
        );
        $this->assertSame('/posts/renamed-title', $oldResponse->headers->get('Location'));

        $newResponse = $this->kernel->handle(Request::create('/posts/renamed-title'));
        $this->assertSame(
            200,
            $newResponse->getStatusCode(),
            'Body: ' . (string) $newResponse->getContent(),
        );
        $this->assertStringContainsString('Renamed Title', (string) $newResponse->getContent());
    }

    public function testFirstSaveOfANewEntryDoesNotCreateARedirect(): void
    {
        $collection = $this->seedCollection();
        $this->entries->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'Brand New'],
        );

        $rows = $this->connection->fetchAll('SELECT old_path FROM redirects');
        $this->assertSame([], $rows, 'no redirect should be created on the first save.');
    }

    public function testLiveEntryTakesPrecedenceOverStaleRedirect(): void
    {
        $collection = $this->seedCollection();
        $saved = $this->entries->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'Live Now'],
        );
        $this->entries->publish($saved->id());

        // Hand-craft a stale redirect pointing at the live entry's URL.
        $this->redirects->upsert('/posts/live-now', '/posts/legacy-target');

        $liveResponse = $this->kernel->handle(Request::create('/posts/live-now'));
        $this->assertSame(200, $liveResponse->getStatusCode());
        $this->assertStringContainsString('Live Now', (string) $liveResponse->getContent());
        $this->assertNull($liveResponse->headers->get('Location'));
    }

    public function testRenamingTheSameEntryTwiceReusesTheRedirectRow(): void
    {
        $collection = $this->seedCollection();
        $saved = $this->entries->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            ['title' => 'Start'],
        );
        $this->entries->save($saved, $collection, ['title' => 'Middle']);
        $this->entries->save($saved, $collection, ['title' => 'Final']);

        $rows = $this->connection->fetchAll('SELECT old_path, new_path FROM redirects ORDER BY id ASC');
        $this->assertCount(2, $rows, 'two slug transitions produce two distinct old paths.');
        $oldPaths = array_column($rows, 'old_path');
        $this->assertContains('/posts/start', $oldPaths);
        $this->assertContains('/posts/middle', $oldPaths);
    }

    private function seedCollection(): \Stead\Content\Collection
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema, :ts, :ts)',
            [
                'slug' => 'posts',
                'name' => 'Posts',
                'schema' => json_encode(['fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Title']]]),
                'ts' => $now,
            ],
        );
        return \Stead\Content\Collection::fromRow([
            'id' => (int) $this->connection->fetchOne('SELECT id FROM collections WHERE slug = :slug', ['slug' => 'posts'])['id'],
            'slug' => 'posts',
            'name' => 'Posts',
            'schema_definition' => json_encode(['fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Title']]]),
        ]);
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
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/base.twig',
            "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<title>{% block title %}{{ collection.name|default('Stead') }}{% endblock %}</title>"
            . "<body class=\"theme-starter\">"
            . "<p class=\"theme-mark\">Starter theme</p>"
            . "{% block body %}{% endblock %}"
            . "</body></html>\n",
        );
        file_put_contents(
            $dir . '/home.twig',
            "{% extends 'base.twig' %}\n"
            . "{% block title %}Home{% endblock %}\n"
            . "{% block body %}<h1>Welcome</h1>{% endblock %}\n",
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