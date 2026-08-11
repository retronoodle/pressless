<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

/**
 * Fixed list of options rendered as a `<select>`. Persists to
 * `entry_values.value_text` (the selected option's stored value).
 *
 * The `options` field supports either a list of strings (value === label) or
 * an associative map of value => label.
 */
final class SelectFieldType implements FieldType
{
    public function key(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return 'Select';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => null,
            'options' => [],
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $normalized['default'] = $field['default'] ?? null;
        $normalized['options'] = $this->asOptions($field['options'] ?? []);
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $required = (bool) ($field['required'] ?? false);
        $options = $this->asOptions($field['options'] ?? []);
        $values = array_keys($options);
        $string = is_scalar($value) ? (string) $value : '';

        if ($string === '') {
            if ($required) {
                return ['This field is required.'];
            }
            return [];
        }

        if (!in_array($string, $values, true)) {
            return ['Must be one of the configured options.'];
        }

        return [];
    }

    public function databaseColumns(): array
    {
        return ['value_text'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        $string = is_scalar($value) ? (string) $value : '';
        return ['value_text' => $string === '' ? null : $string];
    }

    public function bindForRead(array $row): mixed
    {
        return isset($row['value_text']) && $row['value_text'] !== null
            ? (string) $row['value_text']
            : null;
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $required = (bool) ($field['required'] ?? false);
        $options = $this->asOptions($field['options'] ?? []);
        $current = is_scalar($value) ? (string) $value : '';

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>'
            . '<select id="' . self::e($key) . '" name="fields[' . self::e($key) . ']"'
            . ($required ? ' required' : '')
            . '>';

        $html .= '<option value=""' . ($current === '' ? ' selected' : '') . '>— Select —</option>';
        foreach ($options as $optionValue => $optionLabel) {
            $selected = (string) $optionValue === $current ? ' selected' : '';
            $html .= '<option value="' . self::e((string) $optionValue) . '"' . $selected . '>'
                . self::e((string) $optionLabel) . '</option>';
        }
        $html .= '</select>';

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

    /**
     * @return array<string, string>
     */
    private function asOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value => $label) {
            if (is_int($value) && is_string($label)) {
                $out[$label] = $label;
                continue;
            }
            if (is_string($value) && (is_string($label) || is_int($label))) {
                $out[$value] = (string) $label;
            }
        }
        return $out;
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
