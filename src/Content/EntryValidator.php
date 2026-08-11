<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Content\FieldType\FieldTypeRegistry;

/**
 * Validates a submitted entry payload against the collection's field set.
 *
 * Each field's submitted value is dispatched to its registered field type's
 * `validate()`. Errors are accumulated under the field's key so the form
 * template can render them inline. The result is a {@see ValidationResult}
 * that distinguishes the empty-errors case from the errors-by-key case.
 */
final class EntryValidator
{
    public function __construct(private readonly FieldTypeRegistry $fieldTypes)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validate(Collection $collection, array $payload): ValidationResult
    {
        $errors = [];
        foreach ($collection->fields() as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($key === '' || $type === '' || !$this->fieldTypes->has($type)) {
                continue;
            }
            $value = $payload[$key] ?? null;
            $fieldErrors = $this->fieldTypes->get($type)->validate($value, $field);
            if ($fieldErrors !== []) {
                $errors[$key] = $fieldErrors;
            }
        }
        return $errors === [] ? ValidationResult::ok() : ValidationResult::fromErrors($errors);
    }
}
