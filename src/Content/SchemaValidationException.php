<?php

declare(strict_types=1);

namespace Stead\Content;

/**
 * Raised when a collection's proposed schema is invalid: malformed keys,
 * duplicate keys, unknown field types, or per-type options that do not match
 * the type's `schemaDefaults()`.
 *
 * Errors are reported per field key so the admin form can render them
 * inline. The {@see errors()} map always uses the original (or proposed) key
 * as its identifier, even on key-shape failures, so the controller can locate
 * the offending row in the form.
 */
final class SchemaValidationException extends \Stead\Exception\SafeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            'Collection schema is invalid.',
            ['errors' => $errors],
            $previous,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
