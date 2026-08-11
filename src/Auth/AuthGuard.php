<?php

declare(strict_types=1);

namespace Stead\Auth;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps route handlers so they only run for authenticated requests.
 */
final class AuthGuard
{
    public const LOGIN_PATH = '/admin/login';
    public const DEFAULT_TARGET = '/admin';

    public function __construct(private readonly AuthenticationService $auth)
    {
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @return callable(Request, array<string, string>): Response
     */
    public function protect(callable $handler): callable
    {
        return function (Request $request, array $parameters) use ($handler): Response {
            $user = $this->auth->currentUser($request);

            if ($user === null) {
                return new RedirectResponse(self::loginUrlFor($request));
            }

            $request->attributes->set('user', $user);

            return $handler($request, $parameters);
        };
    }

    /**
     * Builds the login URL, preserving the originally requested path when it is
     * a safe local target.
     */
    public static function loginUrlFor(Request $request): string
    {
        $target = $request->getRequestUri();

        if ($request->getPathInfo() === self::LOGIN_PATH || !self::isSafeTarget($target)) {
            return self::LOGIN_PATH;
        }

        return self::LOGIN_PATH . '?redirect=' . rawurlencode($target);
    }

    /**
     * Resolves a post-login destination, falling back to the admin shell when
     * the supplied target is absent or unsafe.
     */
    public static function resolveTarget(?string $target): string
    {
        if ($target === null || !self::isSafeTarget($target)) {
            return self::DEFAULT_TARGET;
        }

        return $target;
    }

    /**
     * Accepts only same-origin absolute paths. Rejects absolute URLs,
     * protocol-relative targets, backslash variants that some browsers
     * normalize to slashes, and header-splitting attempts.
     */
    public static function isSafeTarget(string $target): bool
    {
        if ($target === '' || !str_starts_with($target, '/')) {
            return false;
        }

        if (str_starts_with($target, '//')) {
            return false;
        }

        if (str_contains($target, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $target) === 1) {
            return false;
        }

        return true;
    }
}
