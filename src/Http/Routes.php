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
use Pressless\Http\Controller\AdminController;
use Pressless\Http\Controller\LoginController;
use Pressless\View\Renderer;
use Pressless\View\SimpleRenderer;
use Pressless\View\TwigRenderer;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Phase 1 route table and its explicit service wiring.
 *
 * Services are constructed here rather than resolved from a container, keeping
 * the request lifecycle visible.
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

        return self::register($auth, new TwigRenderer($config));
    }

    /**
     * Registers the initial admin routes against the supplied services.
     */
    public static function register(AuthenticationService $auth, Renderer $renderer): Router
    {
        $guard = new AuthGuard($auth);
        $login = new LoginController($auth, $renderer);
        $admin = new AdminController($renderer);

        $router = new Router();

        $router->get(AuthGuard::LOGIN_PATH, $login->show(...), 'login.show');
        $router->post(AuthGuard::LOGIN_PATH, $login->login(...), 'login.submit');
        $router->post('/admin/logout', $login->logout(...), 'logout');
        $router->get(AuthGuard::DEFAULT_TARGET, $guard->protect($admin->index(...)), 'admin.index');

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

        return self::register($auth, $renderer ?? new SimpleRenderer());
    }
}
