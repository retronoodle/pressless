<?php

declare(strict_types=1);

namespace Stead\Auth;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level wrapper that enforces a collection-scoped permission on the
 * current user. Composes with {@see AuthGuard::protect()}: the guard runs
 * first (so `user` is guaranteed on the request), then this wrapper checks
 * authorization against the collection slug supplied in the route.
 */
final class CollectionAuthorization
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @return callable(Request, array<string, string>): Response
     */
    public function requireAction(string $action, callable $handler): callable
    {
        return function (Request $request, array $parameters) use ($action, $handler): Response {
            $user = $request->attributes->get('user');
            $slug = (string) ($parameters['slug'] ?? '');

            if ($slug === '' || !$this->authorization->can(
                $user instanceof User ? $user : null,
                $slug,
                $action,
            )) {
                return new Response('', Response::HTTP_FORBIDDEN);
            }

            return $handler($request, $parameters);
        };
    }

    /**
     * Wraps any handler so that only admins may run it. Used for collection
     * schema management (create/edit/delete a collection), which is not
     * covered by the per-collection `permissions` table.
     *
     * @param callable(Request, array<string, string>): Response $handler
     * @return callable(Request, array<string, string>): Response
     */
    public function requireAdmin(callable $handler): callable
    {
        return function (Request $request, array $parameters) use ($handler): Response {
            $user = $request->attributes->get('user');
            if (!$user instanceof User || !$user->isAdmin()) {
                return new Response('', Response::HTTP_FORBIDDEN);
            }
            return $handler($request, $parameters);
        };
    }
}
