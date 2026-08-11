<?php

declare(strict_types=1);

namespace Stead\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * An explicit route table matched by HTTP method and normalized path.
 *
 * The matcher is deliberately isolated behind {@see match()} so a third-party
 * matcher can be substituted without changing callers.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable(Request, array<string, string>): \Symfony\Component\HttpFoundation\Response $handler
     */
    public function add(string $method, string $path, callable $handler, ?string $name = null): self
    {
        $this->routes[] = new Route(strtoupper($method), self::normalize($path), $handler, $name);
        return $this;
    }

    /**
     * @param callable(Request, array<string, string>): \Symfony\Component\HttpFoundation\Response $handler
     */
    public function get(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $name);
    }

    /**
     * @param callable(Request, array<string, string>): \Symfony\Component\HttpFoundation\Response $handler
     */
    public function post(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $name);
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function match(Request $request): RouteMatch
    {
        return $this->matchMethodAndPath(
            strtoupper($request->getMethod()),
            self::normalize($request->getPathInfo()),
        );
    }

    public function matchMethodAndPath(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $path = self::normalize($path);

        // HEAD is served by the GET handler; HttpFoundation drops the body.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        $allowed = [];
        foreach ($this->routes as $route) {
            $parameters = $route->matchPath($path);
            if ($parameters === null) {
                continue;
            }
            if ($route->method === $lookupMethod) {
                return RouteMatch::found($route, $parameters);
            }
            $allowed[$route->method] = true;
        }

        if ($allowed !== []) {
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }
            /** @var list<string> $methods */
            $methods = array_keys($allowed);
            sort($methods);
            return RouteMatch::methodNotAllowed($methods);
        }

        return RouteMatch::notFound();
    }

    /**
     * Collapses duplicate slashes and drops a trailing slash so `/admin/` and
     * `/admin` resolve to the same route. The root path stays `/`.
     */
    public static function normalize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        return rtrim($path, '/') ?: '/';
    }
}
