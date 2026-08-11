<?php

declare(strict_types=1);

namespace Pressless\Http;

use Pressless\Auth\AuthenticationService;
use Pressless\Auth\AuthGuard;
use Pressless\Auth\DatabaseSessionHandler;
use Pressless\Auth\NativeSessionStore;
use Pressless\Auth\PasswordHasher;
use Pressless\Auth\SessionRepository;
use Pressless\Auth\SessionStore;
use Pressless\Auth\UserRepository;
use Pressless\Bootstrap\Application;
use Pressless\Content\CollectionRepository;
use Pressless\Content\CollectionSchemaValidator;
use Pressless\Content\EntryRepository;
use Pressless\Content\EntryValidator;
use Pressless\Content\FieldType\BooleanFieldType;
use Pressless\Content\FieldType\DateFieldType;
use Pressless\Content\FieldType\FieldType;
use Pressless\Content\FieldType\FieldTypeRegistry;
use Pressless\Content\FieldType\MediaFieldType;
use Pressless\Content\FieldType\NumberFieldType;
use Pressless\Content\FieldType\RelationFieldType;
use Pressless\Content\FieldType\RichtextFieldType;
use Pressless\Content\FieldType\SelectFieldType;
use Pressless\Content\FieldType\TextFieldType;
use Pressless\Content\SchemaChangeHelper;
use Pressless\Content\SlugGenerator;
use Pressless\Database\Connection;
use Pressless\Http\Controller\AdminController;
use Pressless\Http\Controller\CollectionAdminController;
use Pressless\Http\Controller\EntryAdminController;
use Pressless\Http\Controller\LoginController;
use Pressless\View\Renderer;
use Pressless\View\SimpleRenderer;
use Pressless\View\TwigRenderer;
use Symfony\Component\HttpFoundation\Request;

/**
 * The route table and its explicit service wiring.
 *
 * Services are constructed here rather than resolved from a container, keeping
 * the request lifecycle visible. Each Phase extends this table with its own
 * controllers and routes; the entry-points (`create`, `createWithStore`)
 * remain stable so test harnesses and `bin/serve` can boot without changes.
 */
final class Routes
{
    public static function create(Application $app, ?Request $request = null): Router
    {
        $config = $app->config();
        $connection = $app->database();
        $lifetime = $config->getInt('sessions.lifetime', 7200);

        $sessions = new SessionRepository($connection);
        $hasher = new PasswordHasher();
        $users = new UserRepository($connection, $hasher);

        $handler = new DatabaseSessionHandler($sessions, $lifetime);
        if ($request !== null) {
            $handler->withRequestContext(
                (string) $request->getClientIp(),
                (string) $request->headers->get('User-Agent', ''),
            );
        }

        $store = new NativeSessionStore($config, $handler);
        $auth = new AuthenticationService($users, $sessions, $hasher, $store, $lifetime);

        return self::register(
            $auth,
            new TwigRenderer($config),
            self::buildFieldTypeRegistry(),
            $connection,
        );
    }

    /**
     * Registers the admin routes against the supplied services. Connection is
     * passed in so Phase 2 collection/entry controllers can build their own
     * repositories without a service locator.
     */
    public static function register(
        AuthenticationService $auth,
        Renderer $renderer,
        FieldTypeRegistry $fieldTypes,
        Connection $connection,
    ): Router {
        $guard = new AuthGuard($auth);
        $login = new LoginController($auth, $renderer);

        $collections = new CollectionRepository($connection);
        $schemaValidator = new CollectionSchemaValidator($fieldTypes);
        $schemaChanges = new SchemaChangeHelper($connection);
        $slugs = new SlugGenerator($connection);
        $entryRepository = new EntryRepository($connection, $fieldTypes, $slugs);
        $entryValidator = new EntryValidator($fieldTypes);

        $admin = new AdminController($renderer, $collections, $entryRepository);

        $collectionsController = new CollectionAdminController(
            $renderer,
            $connection,
            $collections,
            $schemaValidator,
            $schemaChanges,
            $fieldTypes,
        );
        $entriesController = new EntryAdminController(
            $renderer,
            $collections,
            $entryRepository,
            $entryValidator,
            $fieldTypes,
            $slugs,
        );

        $router = new Router();

        // Phase 1 — auth + dashboard.
        $router->get(AuthGuard::LOGIN_PATH, $login->show(...), 'login.show');
        $router->post(AuthGuard::LOGIN_PATH, $login->login(...), 'login.submit');
        $router->post('/admin/logout', $login->logout(...), 'logout');
        $router->get(AuthGuard::DEFAULT_TARGET, $guard->protect($admin->index(...)), 'admin.index');

        // Phase 2 Section 8 — collection admin (literal routes must register
        // before their `{slug}` counterparts so "new" is not captured as a
        // slug).
        $router->get('/admin/collections', $guard->protect($collectionsController->index(...)), 'collections.index');
        $router->get('/admin/collections/new', $guard->protect($collectionsController->create(...)), 'collections.create');
        $router->post('/admin/collections/new', $guard->protect($collectionsController->store(...)), 'collections.store');
        $router->get('/admin/collections/{slug}/edit', $guard->protect($collectionsController->edit(...)), 'collections.edit');
        $router->post('/admin/collections/{slug}/edit', $guard->protect($collectionsController->update(...)), 'collections.update');
        $router->post('/admin/collections/{slug}/delete', $guard->protect($collectionsController->destroy(...)), 'collections.destroy');

        // Phase 2 Section 9 — entry admin.
        $router->get('/admin/collections/{slug}', $guard->protect($entriesController->index(...)), 'entries.index');
        $router->get('/admin/collections/{slug}/entries/new', $guard->protect($entriesController->create(...)), 'entries.create');
        $router->post('/admin/collections/{slug}/entries/new', $guard->protect($entriesController->store(...)), 'entries.store');
        $router->get('/admin/collections/{slug}/entries/{id}/edit', $guard->protect($entriesController->edit(...)), 'entries.edit');
        $router->post('/admin/collections/{slug}/entries/{id}/edit', $guard->protect($entriesController->update(...)), 'entries.update');
        $router->post('/admin/collections/{slug}/entries/{id}/delete', $guard->protect($entriesController->destroy(...)), 'entries.destroy');

        return $router;
    }

    /**
     * Builds a router around an explicit session store, used by tests and the
     * seeding path where a native session is not appropriate.
     */
    public static function createWithStore(
        Application $app,
        SessionStore $store,
        ?Renderer $renderer = null,
    ): Router {
        $config = $app->config();
        $connection = $app->database();
        $lifetime = $config->getInt('sessions.lifetime', 7200);

        $sessions = new SessionRepository($connection);
        $hasher = new PasswordHasher();
        $users = new UserRepository($connection, $hasher);
        $auth = new AuthenticationService($users, $sessions, $hasher, $store, $lifetime);

        return self::register(
            $auth,
            $renderer ?? new SimpleRenderer(),
            self::buildFieldTypeRegistry(),
            $connection,
        );
    }

    /**
     * The built-in field types are constructed here, in dependency order, and
     * handed to the registry. This is the only place the eight types are
     * listed — no service locator, no plugin manifest.
     *
     * @return list<FieldType>
     */
    private static function buildBuiltinFieldTypes(): array
    {
        return [
            new TextFieldType(),
            new RichtextFieldType(),
            new NumberFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
            new SelectFieldType(),
            new MediaFieldType(),
            new RelationFieldType(),
        ];
    }

    public static function buildFieldTypeRegistry(): FieldTypeRegistry
    {
        return new FieldTypeRegistry(self::buildBuiltinFieldTypes());
    }
}
