<?php

declare(strict_types=1);

namespace Pressless\Http;

/**
 * The outcome of matching a request against the route table.
 */
final class RouteMatch
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * @param array<string, string> $parameters
     * @param list<string> $allowedMethods
     */
    private function __construct(
        public readonly string $status,
        public readonly ?Route $route = null,
        public readonly array $parameters = [],
        public readonly array $allowedMethods = [],
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public static function found(Route $route, array $parameters = []): self
    {
        return new self(self::FOUND, $route, $parameters);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
