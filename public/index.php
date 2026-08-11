<?php

/**
 * Pressless front controller.
 *
 * Every dynamic request enters here: bootstrap the application, dispatch the
 * route table, and emit exactly one response.
 */

declare(strict_types=1);

use Pressless\Bootstrap\Application;
use Pressless\Http\Kernel;
use Pressless\Http\Routes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();

try {
    $app = Application::bootstrap();
} catch (Throwable $exception) {
    // Bootstrap failed before the logger or exception handler existed, so
    // respond with a fixed safe body rather than anything derived from the error.
    error_log('Pressless bootstrap failure: ' . $exception->getMessage());

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
