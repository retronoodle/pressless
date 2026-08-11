<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

/**
 * Boolean toggle rendered as a labeled checkbox. Persists to
 * `entry_values.value_bool`.
 *
 * Forms always submit a value (`0` when unchecked, `1` when checked) via a
 * hidden companion input so the read side never has to distinguish
 * "unchecked" from "missing".
 */
final class BooleanFieldType implements FieldType
{
    public function key(): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return 'Boolean';
    }

    public function schemaDefaults(): array
    {
        return [
            'default' => false,
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['default'] = $this->asBool($field['default'] ?? false);
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        if (!$this->looksBoolish($value)) {
            return ['Must be a boolean value.'];
        }
        return [];
    }

    public function databaseColumns(): array
    {
        return ['value_bool'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        return ['value_bool' => $this->asBool($value) ? 1 : 0];
    }

    public function bindForRead(array $row): mixed
    {
        if (!array_key_exists('value_bool', $row) || $row['value_bool'] === null) {
            return null;
        }
        return (bool) $row['value_bool'];
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $checked = $this->asBool($value) ? ' checked' : '';

        $html = '<input type="hidden" name="fields[' . self::e($key) . ']" value="0">'
            . '<label class="boolean-field">'
            . '<input type="checkbox" id="' . self::e($key) . '" name="fields[' . self::e($key) . ']"'
            . ' value="1"' . $checked . '> '
            . self::e($label)
            . '</label>';

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

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return $normalized === '1' || $normalized === 'true' || $normalized === 'on' || $normalized === 'yes';
        }
        return false;
    }

    private function looksBoolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return true;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['', '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true);
        }
        return false;
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
