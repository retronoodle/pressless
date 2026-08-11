<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

/**
 * Floating-point number with optional min/max bounds. Persists to
 * `entry_values.value_number`.
 */
final class NumberFieldType implements FieldType
{
    public function key(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'Number';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => null,
            'min' => null,
            'max' => null,
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $normalized['default'] = $field['default'] ?? null;
        $normalized['min'] = $this->asNullableNumber($field['min'] ?? null);
        $normalized['max'] = $this->asNullableNumber($field['max'] ?? null);
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $errors = [];
        $required = (bool) ($field['required'] ?? false);
        $string = is_scalar($value) ? trim((string) $value) : '';

        if ($string === '') {
            if ($required) {
                $errors[] = 'This field is required.';
            }
            return $errors;
        }

        if (!is_numeric($string)) {
            $errors[] = 'Must be a number.';
            return $errors;
        }

        $number = (float) $string;
        $min = $field['min'] ?? null;
        $max = $field['max'] ?? null;

        if (is_numeric($min) && $number < (float) $min) {
            $errors[] = sprintf('Must be at least %s.', self::formatBound($min));
        }
        if (is_numeric($max) && $number > (float) $max) {
            $errors[] = sprintf('Must be at most %s.', self::formatBound($max));
        }

        return $errors;
    }

    public function databaseColumns(): array
    {
        return ['value_number'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['value_number' => null];
        }
        $string = is_scalar($value) ? trim((string) $value) : '';
        if ($string === '' || !is_numeric($string)) {
            return ['value_number' => null];
        }
        return ['value_number' => (float) $string];
    }

    public function bindForRead(array $row): mixed
    {
        if (!array_key_exists('value_number', $row) || $row['value_number'] === null || $row['value_number'] === '') {
            return null;
        }
        return (float) $row['value_number'];
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $required = (bool) ($field['required'] ?? false);
        $min = $field['min'] ?? null;
        $max = $field['max'] ?? null;
        $current = is_scalar($value) ? (string) $value : '';

        $attrs = ' step="any"';
        if (is_numeric($min)) {
            $attrs .= ' min="' . self::formatBound($min) . '"';
        }
        if (is_numeric($max)) {
            $attrs .= ' max="' . self::formatBound($max) . '"';
        }

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>'
            . '<input type="number" id="' . self::e($key) . '" name="fields[' . self::e($key) . ']"'
            . ' value="' . self::e($current) . '"'
            . $attrs
            . ($required ? ' required' : '')
            . '>';

        return $html . self::renderErrors($errors);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function preserveIdentity(array $field): array
    {
        $out = [];
        foreach (['key', 'type', 'label'] as $name) {
            if (array_key_exists($name, $field)) {
                $out[$name] = $field[$name];
            }
        }
        return $out;
    }

    private function asNullableNumber(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        return null;
    }

    private static function formatBound(mixed $bound): string
    {
        if (is_int($bound)) {
            return (string) $bound;
        }
        return (string) (float) $bound;
    }

    /**
     * @param list<string> $errors
     */
    private static function renderErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }
        $items = '';
        foreach ($errors as $message) {
            $items .= '<li>' . self::e($message) . '</li>';
        }
        return '<ul class="field-errors" role="alert">' . $items . '</ul>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
