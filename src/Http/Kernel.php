<?php

declare(strict_types=1);

namespace Stead\Http;

use Stead\Bootstrap\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turns a request into exactly one response: match a route, run its handler,
 * and translate routing failures and uncaught exceptions into safe responses.
 */
final class Kernel
{
    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request);

            if ($match->status === RouteMatch::NOT_FOUND) {
                return $this->notFound($request);
            }

            if ($match->status === RouteMatch::METHOD_NOT_ALLOWED) {
                return $this->methodNotAllowed($request, $match->allowedMethods);
            }

            /** @var Route $route */
            $route = $match->route;
            $response = ($route->handler())($request, $match->parameters);

            return $response->prepare($request);
        } catch (Throwable $exception) {
            $this->app->exceptionHandler()->report($exception);
            $response = $this->app->exceptionHandler()->render(
                $exception,
                $request->headers->get('Accept'),
            );
            return $response->prepare($request);
        }
    }

    /**
     * Sends the response and closes the request lifecycle.
     */
    public function terminate(Request $request, Response $response): void
    {
        $response->send();
    }

    private function notFound(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            return new Response(
                (string) json_encode(['error' => 'Not Found']),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/json'],
            );
        }

        return new Response(
            $this->document('Not found', 'The page you requested does not exist.'),
            Response::HTTP_NOT_FOUND,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * @param list<string> $allowedMethods
     */
    private function methodNotAllowed(Request $request, array $allowedMethods): Response
    {
        $allow = implode(', ', $allowedMethods);

        if ($this->wantsJson($request)) {
            return new Response(
                (string) json_encode(['error' => 'Method Not Allowed', 'allow' => $allowedMethods]),
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Content-Type' => 'application/json', 'Allow' => $allow],
            );
        }

        return new Response(
            $this->document('Method not allowed', 'This address does not accept that request method.'),
            Response::HTTP_METHOD_NOT_ALLOWED,
            ['Content-Type' => 'text/html; charset=utf-8', 'Allow' => $allow],
        );
    }

    private function wantsJson(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');
        return str_contains($accept, 'application/json');
    }

    private function document(string $title, string $message): string
    {
        return sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>%1$s</title></head><body><h1>%1$s</h1><p>%2$s</p></body></html>',
            htmlspecialchars($title, ENT_QUOTES),
            htmlspecialchars($message, ENT_QUOTES),
        );
    }
}
