<?php

declare(strict_types=1);

namespace Stead\Installer;

use Stead\Exception\SafeException;
use Stead\Http\Router;
use Stead\Installer\Controller\InstallerController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The installer's request-to-response loop.
 *
 * Mirrors the application's {@see \Stead\Http\Kernel} but without an
 * {@see \Stead\Bootstrap\Application}: the installer has no logger, no
 * exception handler, and no database connection outside of the wizard's
 * connection test. Errors surface inline as a controlled 500 with a clear
 * message instead of routing through the application's report path.
 */
final class InstallerKernel
{
    public function __construct(
        private readonly Router $router,
    ) {
    }

    public static function create(InstallerController $controller): self
    {
        return new self(InstallerRoutes::create($controller));
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request);

            if ($match->status === \Stead\Http\RouteMatch::NOT_FOUND) {
                return $this->notFound($request);
            }

            if ($match->status === \Stead\Http\RouteMatch::METHOD_NOT_ALLOWED) {
                return $this->methodNotAllowed($request, $match->allowedMethods);
            }

            /** @var \Stead\Http\Route $route */
            $route = $match->route;
            $response = ($route->handler())($request, $match->parameters);

            return $response->prepare($request);
        } catch (SafeException $exception) {
            return $this->safeResponse($exception, $request);
        } catch (Throwable $exception) {
            error_log('Installer failure: ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
            return $this->safeResponse(
                new SafeException('The installer encountered an unexpected error.'),
                $request,
            );
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $response->send();
    }

    private function notFound(Request $request): Response
    {
        return new Response(
            $this->document('Not found', 'The installer has no page at this address.'),
            Response::HTTP_NOT_FOUND,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * @param list<string> $allowedMethods
     */
    private function methodNotAllowed(Request $request, array $allowedMethods): Response
    {
        return new Response(
            $this->document('Method not allowed', 'This address does not accept that request method.'),
            Response::HTTP_METHOD_NOT_ALLOWED,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Allow' => implode(', ', $allowedMethods),
            ],
        );
    }

    private function safeResponse(SafeException $exception, Request $request): Response
    {
        return new Response(
            $this->document(
                'Installer error',
                htmlspecialchars($exception->publicMessage(), ENT_QUOTES, 'UTF-8'),
            ),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function document(string $title, string $body): string
    {
        return sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>%1$s</title></head><body>'
            . '<main class="installer-shell"><h1>%1$s</h1><p>%2$s</p>'
            . '<p><a href="/install">Restart the installer</a></p></main>'
            . '</body></html>',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            $body,
        );
    }
}