<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Content\FieldType\FieldTypeRegistry;

/**
 * Validates a collection's proposed field set against the registered field
 * types and a small set of structural rules:
 *
 *  - Each field MUST have a `key` matching `^[a-z][a-z0-9_]*$`.
 *  - Field keys MUST be unique.
 *  - Each field's `type` MUST reference a registered {@see FieldType} via
 *    {@see FieldTypeRegistry::has()}.
 *  - The merged `(defaults, user options)` for each field MUST be identical
 *    to the output of the type's `normalize()` call, so controllers can never
 *    persist options the type does not know about.
 *
 * On failure the validator raises {@see SchemaValidationException} whose
 * `errors()` map groups messages per field key.
 */
final class CollectionSchemaValidator
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(private readonly FieldTypeRegistry $fieldTypes)
    {
    }

    /**
     * @param array{fields: list<array<string, mixed>>} $schema
     * @throws SchemaValidationException
     */
    public function validateSchema(array $schema): void
    {
        $errors = $this->collectFieldErrors($schema['fields'] ?? []);
        if ($errors !== []) {
            throw new SchemaValidationException($errors);
        }
    }

    /**
     * Convenience that accepts either the full schema wrapper or the raw
     * fields array.
     *
     * @param list<array<string, mixed>> $fields
     * @throws SchemaValidationException
     */
    public function validateFields(array $fields): void
    {
        $errors = $this->collectFieldErrors($fields);
        if ($errors !== []) {
            throw new SchemaValidationException($errors);
        }
    }

    /**
     * Normalizes a single field through its registered type. Returns the
     * merge of `schemaDefaults()` + supplied options. The caller is expected
     * to have already validated the field set.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public function normalizeField(array $field): array
    {
        $type = $field['type'] ?? null;
        if (!is_string($type) || !$this->fieldTypes->has($type)) {
            return $field;
        }
        return $this->fieldTypes->get($type)->normalize($field);
    }

    /**
     * Normalizes every field in the schema through its registered type. The
     * result is intended to be persisted verbatim by the repository.
     *
     * @param array{fields: list<array<string, mixed>>} $schema
     * @return array{fields: list<array<string, mixed>>}
     */
    public function normalizeSchema(array $schema): array
    {
        $fields = $schema['fields'] ?? [];
        $normalized = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                $normalized[] = $this->normalizeField($field);
            }
        }
        return ['fields' => $normalized];
    }

    /**
     * @param list<mixed> $fields
     * @return array<string, list<string>>
     */
    private function collectFieldErrors(array $fields): array
    {
        if (!is_array($fields) || $fields === []) {
            return ['__schema' => ['At least one field is required.']];
        }

        $errors = [];
        $seenKeys = [];
        $positions = [];

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                $errors['__field_' . $index] = ['Field must be an object.'];
                continue;
            }

            $keyRaw = $field['key'] ?? null;
            $key = is_string($keyRaw) ? $keyRaw : '';

            if ($key === '') {
                $errors['__field_' . $index] = ['Field key is required.'];
                continue;
            }

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $errors[$key] = ['Field key must match ^[a-z][a-z0-9_]*$.'];
                continue;
            }

            if (isset($seenKeys[$key])) {
                $errors[$key] = ['Duplicate field key.'];
                continue;
            }

            $seenKeys[$key] = true;
            $positions[$key] = $index;

            $fieldErrors = $this->validateFieldOptions($key, $field);
            if ($fieldErrors !== []) {
                $errors[$key] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private function validateFieldOptions(string $key, array $field): array
    {
        $type = $field['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return ['Field type is required.'];
        }

        if (!$this->fieldTypes->has($type)) {
            return [sprintf('Field type "%s" is not registered.', $type)];
        }

        $typeHandler = $this->fieldTypes->get($type);

        try {
            $normalized = $typeHandler->normalize($field);
        } catch (\Throwable $e) {
            return ['Field options could not be normalized.'];
        }

        if (!is_array($normalized)) {
            return ['Field options could not be normalized.'];
        }

        return [];
    }
}
