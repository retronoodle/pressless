<?php

declare(strict_types=1);

namespace Stead\Http;

/**
 * A single registered route. Paths may contain `{name}` placeholders, each of
 * which matches one path segment and is passed to the handler as a parameter.
 */
final class Route
{
    /** @var list<string> */
    private array $parameterNames = [];
    private string $pattern;

    /**
     * @param callable(\Symfony\Component\HttpFoundation\Request, array<string, string>): \Symfony\Component\HttpFoundation\Response $handler
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private $handler,
        public readonly ?string $name = null,
    ) {
        $this->pattern = $this->compile($path);
    }

    /**
     * @return callable(\Symfony\Component\HttpFoundation\Request, array<string, string>): \Symfony\Component\HttpFoundation\Response
     */
    public function handler(): callable
    {
        return $this->handler;
    }

    /**
     * Matches a normalized path, returning extracted parameters or null.
     *
     * @return array<string, string>|null
     */
    public function matchPath(string $path): ?array
    {
        if ($this->parameterNames === []) {
            return $path === $this->path ? [] : null;
        }

        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($this->parameterNames as $parameter) {
            $params[$parameter] = $matches[$parameter] ?? '';
        }
        return $params;
    }

    private function compile(string $path): string
    {
        // Split on placeholders so literal segments can be quoted without the
        // braces themselves being escaped.
        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
            $path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        $pattern = '';
        foreach ($parts ?: [] as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $matches) === 1) {
                $this->parameterNames[] = $matches[1];
                $pattern .= '(?P<' . $matches[1] . '>[^/]+)';
                continue;
            }
            $pattern .= preg_quote($part, '#');
        }

        return '#^' . $pattern . '$#';
    }
}
