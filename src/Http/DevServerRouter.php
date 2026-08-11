<?php

declare(strict_types=1);

namespace Stead\Http;

/**
 * The static-file decision used by the `php -S` router script.
 *
 * Kept here rather than inline in the router script so the containment rules
 * are covered by tests.
 */
final class DevServerRouter
{
    /**
     * Decides whether the built-in server should serve a path directly.
     *
     * Returns true only for a regular file that resolves inside the public
     * document root, so traversal attempts cannot reach source, configuration,
     * migration, or seed files that live outside it.
     */
    public static function shouldServeStatic(string $publicDir, string $requestPath): bool
    {
        $path = self::pathFromRequest($requestPath);

        if ($path === '' || $path === '/') {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        $root = realpath($publicDir);
        if ($root === false) {
            return false;
        }

        $candidate = realpath($root . '/' . ltrim($path, '/'));
        if ($candidate === false || !is_file($candidate)) {
            return false;
        }

        // The front controller must always run through the application.
        if ($candidate === $root . '/index.php' || $candidate === $root . '/router.php') {
            return false;
        }

        return str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Extracts the decoded path component of a request URI.
     */
    public static function pathFromRequest(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '';
        }

        return rawurldecode($path);
    }
}
