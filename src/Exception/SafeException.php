<?php

declare(strict_types=1);

namespace Stead\Exception;

use RuntimeException;

class SafeException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    public function hasSecretInMessage(): bool
    {
        $message = strtolower($this->getMessage());
        foreach (['password', 'secret', 'token', 'session_payload', 'cookie_value'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }
}
