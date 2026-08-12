<?php

declare(strict_types=1);

namespace Stead\Http;

use Stead\Auth\AuthorizationService;
use Stead\Auth\AuthenticationService;
use Stead\Auth\AuthGuard;
use Stead\Auth\CollectionAuthorization;
use Stead\Auth\DatabaseSessionHandler;
use Stead\Auth\LoginAttemptRepository;
use Stead\Auth\LoginThrottle;
use Stead\Auth\NativeSessionStore;
use Stead\Auth\PasswordHasher;
use Stead\Auth\PermissionRepository;
use Stead\Auth\SessionRepository;
use Stead\Auth\SessionStore;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Content\CollectionRepository;
use Stead\Content\CollectionSchemaValidator;
use Stead\Content\EntryRepository;
use Stead\Content\EntryValidator;
use Stead\Content\RevisionRepository;
use Stead\Content\FieldType\BooleanFieldType;
use Stead\Content\FieldType\DateFieldType;
use Stead\Content\FieldType\FieldType;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\FieldType\MediaFieldType;
use Stead\Content\FieldType\NumberFieldType;
use Stead\Content\FieldType\RelationFieldType;
use Stead\Content\FieldType\RichtextFieldType;
use Stead\Content\FieldType\SelectFieldType;
use Stead\Content\FieldType\TextFieldType;
use Stead\Content\SchemaChangeHelper;
use Stead\Content\SlugGenerator;
use Stead\Database\Connection;
use Stead\Http\Cache\CollectionVersionStore;
use Stead\Http\Cache\PageCache;
use Stead\Http\Controller\AdminController;
use Stead\Http\Controller\AssetController;
use Stead\Http\Controller\CollectionAdminController;
use Stead\Http\Controller\EntryAdminController;
use Stead\Http\Controller\InviteAcceptController;
use Stead\Http\Controller\InviteAdminController;
use Stead\Http\Controller\LoginController;
use Stead\Http\Controller\MailSettingsAdminController;
use Stead\Http\Controller\MediaAdminController;
use Stead\Http\Controller\MediaServeController;
use Stead\Http\Controller\PermissionAdminController;
use Stead\Http\Controller\PublicController;
use Stead\Http\Controller\UserAdminController;
use Stead\Invites\InviteRepository;
use Stead\Mail\MailSettingsRepository;
use Stead\Mail\SmtpTransport;
use Stead\Media\GdImageTransformer;
use Stead\Media\LocalStorage;
use Stead\Media\MediaRepository;
use Stead\Media\TransformCache;
use Stead\View\Renderer;
use Stead\View\SimpleRenderer;
use Stead\View\TwigRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $loginThrottle = new LoginThrottle(new LoginAttemptRepository($connection), $config);

        return self::register(
            $auth,
            new TwigRenderer($config),
            self::buildFieldTypeRegistry(new MediaRepository($connection)),
            $connection,
            $config,
            $loginThrottle,
        );
    }

    /**
     * Registers the admin routes against the supplied services. Connection is
     * passed in so Phase 2 collection/entry controllers can build their own
     * repositories without a service locator. Configuration is passed in so
     * file-based caches (Phase 4+) can resolve `paths.cache` without a
     * service locator.
     */
    public static function register(
        AuthenticationService $auth,
        Renderer $renderer,
        FieldTypeRegistry $fieldTypes,
        Connection $connection,
        Configuration $config,
        ?LoginThrottle $loginThrottle = null,
    ): Router {
        $guard = new AuthGuard($auth);
        $loginThrottle ??= new LoginThrottle(new LoginAttemptRepository($connection), $config);
        $login = new LoginController($auth, $renderer, $loginThrottle);

        $collections = new CollectionRepository($connection);
        $schemaValidator = new CollectionSchemaValidator($fieldTypes);
        $schemaChanges = new SchemaChangeHelper($connection);
        $slugs = new SlugGenerator($connection);
        $revisionRepository = new RevisionRepository($connection);
        $entryRepository = new EntryRepository($connection, $fieldTypes, $slugs, $revisionRepository, $config);
        $entryValidator = new EntryValidator($fieldTypes);
        $versions = new CollectionVersionStore($config->path('paths.cache'));
        $pageCache = new PageCache($config->path('paths.cache'));

        $mediaRepository = new MediaRepository($connection);
        $storage = new LocalStorage($config);
        $transformer = new GdImageTransformer();
        $transforms = new TransformCache($storage, $transformer);

        $permissions = new PermissionRepository($connection);
        $authorization = new AuthorizationService($collections, $permissions);
        $collectionAuth = new CollectionAuthorization($authorization);

        $users = new UserRepository($connection, new PasswordHasher());

        $admin = new AdminController($renderer, $collections, $entryRepository, $authorization);

        $collectionsController = new CollectionAdminController(
            $renderer,
            $connection,
            $collections,
            $schemaValidator,
            $schemaChanges,
            $fieldTypes,
            $authorization,
        );
        $entriesController = new EntryAdminController(
            $renderer,
            $collections,
            $entryRepository,
            $entryValidator,
            $fieldTypes,
            $slugs,
            $versions,
            $revisionRepository,
            $authorization,
        );

        $mediaAdminController = new MediaAdminController($renderer, $mediaRepository, $storage, $config);
        $mediaServeController = new MediaServeController($mediaRepository, $storage, $transforms);

        $publicController = new PublicController($renderer, $collections, $entryRepository, $pageCache, $versions);
        $assetController = new AssetController($config);

        $userAdminController = new UserAdminController($renderer, $users);
        $permissionAdminController = new PermissionAdminController(
            $renderer,
            $users,
            $collections,
            $permissions,
        );

        $mailSettings = new MailSettingsRepository($connection);
        $smtpTransport = new SmtpTransport($mailSettings);
        $mailSettingsAdminController = new MailSettingsAdminController(
            $renderer,
            $mailSettings,
            $smtpTransport,
            $config,
        );

        $inviteRepository = new InviteRepository($connection);
        $inviteAdminController = new InviteAdminController(
            $renderer,
            $inviteRepository,
            $users,
            $smtpTransport,
            $mailSettings,
            $config,
        );
        $inviteAcceptController = new InviteAcceptController(
            $renderer,
            $inviteRepository,
            $users,
            $auth,
        );

        $router = new Router();

        // Phase 1 — auth + dashboard.
        $router->get(AuthGuard::LOGIN_PATH, $login->show(...), 'login.show');
        $router->post(AuthGuard::LOGIN_PATH, $login->login(...), 'login.submit');
        $router->post('/admin/logout', $login->logout(...), 'logout');
        $router->get(AuthGuard::DEFAULT_TARGET, $guard->protect($admin->index(...)), 'admin.index');

        // Phase 2 Section 8 — collection schema management (admin-only; the
        // permission editor covers per-collection entry actions, not schema).
        $router->get('/admin/collections', $guard->protect($collectionsController->index(...)), 'collections.index');
        $router->get('/admin/collections/new', $guard->protect($collectionAuth->requireAdmin($collectionsController->create(...))), 'collections.create');
        $router->post('/admin/collections/new', $guard->protect($collectionAuth->requireAdmin($collectionsController->store(...))), 'collections.store');
        $router->get('/admin/collections/{slug}/edit', $guard->protect($collectionAuth->requireAdmin($collectionsController->edit(...))), 'collections.edit');
        $router->post('/admin/collections/{slug}/edit', $guard->protect($collectionAuth->requireAdmin($collectionsController->update(...))), 'collections.update');
        $router->post('/admin/collections/{slug}/delete', $guard->protect($collectionAuth->requireAdmin($collectionsController->destroy(...))), 'collections.destroy');

        // Phase 2 Section 9 — entry admin. Collection-scoped entry routes
        // are gated by CollectionAuthorization; the entry controllers add
        // entry-level ownership checks inline so authors can't edit other
        // authors' entries.
        $router->get('/admin/collections/{slug}', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_VIEW, $entriesController->index(...))), 'entries.index');
        $router->get('/admin/collections/{slug}/entries/new', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_CREATE, $entriesController->create(...))), 'entries.create');
        $router->post('/admin/collections/{slug}/entries/new', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_CREATE, $entriesController->store(...))), 'entries.store');
        $router->get('/admin/collections/{slug}/entries/{id}/edit', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_EDIT, $entriesController->edit(...))), 'entries.edit');
        $router->post('/admin/collections/{slug}/entries/{id}/edit', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_EDIT, $entriesController->update(...))), 'entries.update');
        $router->post('/admin/collections/{slug}/entries/{id}/delete', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_DELETE, $entriesController->destroy(...))), 'entries.destroy');
        $router->post('/admin/collections/{slug}/entries/{id}/publish', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_PUBLISH, $entriesController->publish(...))), 'entries.publish');
        $router->post('/admin/collections/{slug}/entries/{id}/unpublish', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_PUBLISH, $entriesController->unpublish(...))), 'entries.unpublish');
        $router->get('/admin/collections/{slug}/entries/{id}/revisions', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_VIEW, $entriesController->revisions(...))), 'entries.revisions');
        $router->post('/admin/collections/{slug}/entries/{id}/revisions/{revisionId}/restore', $guard->protect($collectionAuth->requireAction(PermissionRepository::ACTION_EDIT, $entriesController->restore(...))), 'entries.restore');

        // Phase 5 — media library admin (any authenticated user; per-collection
        // scoping is deferred per the design doc).
        $router->get('/admin/media', $guard->protect($mediaAdminController->index(...)), 'media.index');
        $router->post('/admin/media', $guard->protect($mediaAdminController->upload(...)), 'media.upload');

        // Phase 7 — user management + permission editor (admin-only).
        $router->get('/admin/users', $guard->protect($collectionAuth->requireAdmin($userAdminController->index(...))), 'users.index');
        $router->get('/admin/users/new', $guard->protect($collectionAuth->requireAdmin($userAdminController->create(...))), 'users.create');
        $router->post('/admin/users/new', $guard->protect($collectionAuth->requireAdmin($userAdminController->store(...))), 'users.store');
        $router->get('/admin/users/{id}/role', $guard->protect($collectionAuth->requireAdmin($userAdminController->editRole(...))), 'users.role');
        $router->post('/admin/users/{id}/role', $guard->protect($collectionAuth->requireAdmin($userAdminController->updateRole(...))), 'users.role.update');
        $router->get('/admin/permissions', $guard->protect($collectionAuth->requireAdmin($permissionAdminController->index(...))), 'permissions.index');
        $router->post('/admin/permissions', $guard->protect($collectionAuth->requireAdmin($permissionAdminController->update(...))), 'permissions.update');

        // Phase 8 — mail settings + invite flow (admin-only).
        $router->get('/admin/mail-settings', $guard->protect($collectionAuth->requireAdmin($mailSettingsAdminController->index(...))), 'mail-settings.index');
        $router->post('/admin/mail-settings', $guard->protect($collectionAuth->requireAdmin($mailSettingsAdminController->save(...))), 'mail-settings.save');
        $router->post('/admin/mail-settings/test-send', $guard->protect($collectionAuth->requireAdmin($mailSettingsAdminController->testSend(...))), 'mail-settings.test-send');

        $router->get('/admin/invites', $guard->protect($collectionAuth->requireAdmin(static function (): RedirectResponse {
            return new RedirectResponse('/admin/invites/new', Response::HTTP_SEE_OTHER);
        })), 'invites.index');
        $router->get('/admin/invites/new', $guard->protect($collectionAuth->requireAdmin($inviteAdminController->create(...))), 'invites.create');
        $router->post('/admin/invites/new', $guard->protect($collectionAuth->requireAdmin($inviteAdminController->store(...))), 'invites.store');

        // Public invite-acceptance — must NOT be behind the admin guard.
        $router->get('/invite/accept/{token}', $inviteAcceptController->show(...), 'invites.accept');
        $router->post('/invite/accept/{token}', $inviteAcceptController->submit(...), 'invites.accept.submit');

        // Phase 4 — static assets from the active theme's `assets/` directory.
        // Mounted ahead of the public collection pattern so a literal `/assets/*`
        // prefix always wins, even if a collection is named `assets`.
        $router->get('/assets/{path}', $assetController->serve(...), 'public.asset');

        // Phase 5 — public media serving. Mounted ahead of the public
        // collection pattern so `/media/{id}/{key}` doesn't get misread as a
        // collection slug.
        $router->get('/media/{id}/{key}', $mediaServeController->serve(...), 'public.media');

        // Phase 3 — public collection listing and single entry pages. These
        // patterns are registered last so the literal admin paths above win.
        $router->get('/', $publicController->home(...), 'public.home');
        $router->get('/{collectionSlug}', $publicController->collection(...), 'public.collection');
        $router->get('/{collectionSlug}/{entrySlug}', $publicController->entry(...), 'public.entry');

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
        $loginThrottle = new LoginThrottle(new LoginAttemptRepository($connection), $config);

        return self::register(
            $auth,
            $renderer ?? new SimpleRenderer(),
            self::buildFieldTypeRegistry(new MediaRepository($connection)),
            $connection,
            $config,
            $loginThrottle,
        );
    }

    /**
     * The built-in field types are constructed here, in dependency order, and
     * handed to the registry. This is the only place the eight types are
     * listed — no service locator, no plugin manifest.
     *
     * @return list<FieldType>
     */
    private static function buildBuiltinFieldTypes(?MediaRepository $media = null): array
    {
        return [
            new TextFieldType(),
            new RichtextFieldType(),
            new NumberFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
            new SelectFieldType(),
            new MediaFieldType($media),
            new RelationFieldType(),
        ];
    }

    public static function buildFieldTypeRegistry(?MediaRepository $media = null): FieldTypeRegistry
    {
        return new FieldTypeRegistry(self::buildBuiltinFieldTypes($media));
    }
}
