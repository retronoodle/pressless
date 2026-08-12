<?php

/**
 * Stead front controller.
 *
 * Every dynamic request enters here. Before bootstrap, the front controller
 * checks for `installed.lock` at the project root:
 *
 *   - If the lock is absent, the request is routed into the installer's own
 *     kernel so a fresh extraction can be configured through the browser
 *     before `.env` or a database connection exist.
 *   - If the lock is present, `/install/*` requests are redirected to
 *     `/admin` (or 404 for non-page installer endpoints), and everything
 *     else proceeds through the normal bootstrap.
 */

declare(strict_types=1);

use Stead\Bootstrap\Application;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\Installer\ConfigWriter;
use Stead\Installer\ConnectionTester;
use Stead\Installer\Controller\InstallerController;
use Stead\Installer\InstallerLock;
use Stead\Installer\InstallerRenderer;
use Stead\Installer\InstallerKernel;
use Stead\Installer\NativeInstallerSession;
use Stead\Support\ProjectRoot;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();
$projectRoot = ProjectRoot::resolve();

if (!InstallerLock::isInstalled($projectRoot)) {
    $cacheDir = $projectRoot . '/var/cache';
    $controller = new InstallerController(
        new NativeInstallerSession(),
        new InstallerRenderer($projectRoot, $cacheDir),
        new ConnectionTester(),
        new ConfigWriter(),
        $projectRoot,
    );
    $kernel = InstallerKernel::create($controller);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return;
}

if (str_starts_with($request->getPathInfo(), '/install')) {
    (new RedirectResponse('/admin', Response::HTTP_SEE_OTHER))->prepare($request)->send();
    return;
}

try {
    $app = Application::bootstrap($projectRoot);
} catch (Throwable $exception) {
    // Bootstrap failed before the logger or exception handler existed, so
    // respond with a fixed safe body rather than anything derived from the error.
    error_log('Stead bootstrap failure: ' . $exception->getMessage());

    (new Response(
        'The application is not configured correctly.',
        Response::HTTP_INTERNAL_SERVER_ERROR,
        ['Content-Type' => 'text/plain; charset=utf-8'],
    ))->prepare($request)->send();

    exit(1);
}

$kernel = new Kernel($app, Routes::create($app, $request));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);