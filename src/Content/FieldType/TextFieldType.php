<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

/**
 * Single-line plain text. Persists to `entry_values.value_text`.
 */
final class TextFieldType implements FieldType
{
    private const MAX_LENGTH_DEFAULT = 255;

    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Text';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => null,
            'max_length' => self::MAX_LENGTH_DEFAULT,
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $normalized['default'] = $field['default'] ?? null;
        $max = $field['max_length'] ?? self::MAX_LENGTH_DEFAULT;
        $normalized['max_length'] = is_int($max) && $max > 0 ? $max : self::MAX_LENGTH_DEFAULT;
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $errors = [];
        $string = $this->asString($value);
        $required = (bool) ($field['required'] ?? false);
        $maxLength = (int) ($field['max_length'] ?? self::MAX_LENGTH_DEFAULT);

        if ($required && $string === '') {
            $errors[] = 'This field is required.';
            return $errors;
        }

        if ($string !== '' && mb_strlen($string) > $maxLength) {
            $errors[] = sprintf('Must be at most %d characters.', $maxLength);
        }

        return $errors;
    }

    public function databaseColumns(): array
    {
        return ['value_text'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        $string = $this->asString($value);
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
        $max = (int) ($field['max_length'] ?? self::MAX_LENGTH_DEFAULT);
        $required = (bool) ($field['required'] ?? false);
        $current = $this->asString($value);

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>'
            . '<input type="text" id="' . self::e($key) . '" name="fields[' . self::e($key) . ']"'
            . ' value="' . self::e($current) . '"'
            . ' maxlength="' . $max . '"'
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

    private function asString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return is_scalar($value) ? (string) $value : '';
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
