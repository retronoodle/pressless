<?php

declare(strict_types=1);

namespace Stead\Content;

/**
 * The result of running {@see EntryValidator::validate()} against a payload.
 *
 * The shape distinguishes "no errors" (`hasErrors() === false`) from "errors
 * grouped by field key" so controllers and templates can branch on the bool
 * without inspecting the errors map's shape.
 */
final class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors map of field_key => list of messages
     */
    public function __construct(private readonly array $errors)
    {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function errorsFor(string $fieldKey): array
    {
        return $this->errors[$fieldKey] ?? [];
    }

    /**
     * Builds an empty (passing) result.
     */
    public static function ok(): self
    {
        return new self([]);
    }

    /**
     * Builds a failing result from the supplied error map.
     *
     * @param array<string, list<string>> $errors
     */
    public static function fromErrors(array $errors): self
    {
        $clean = [];
        foreach ($errors as $key => $messages) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!is_array($messages)) {
                continue;
            }
            $list = [];
            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    $list[] = $message;
                }
            }
            if ($list !== []) {
                $clean[$key] = $list;
            }
        }
        return new self($clean);
    }
}
