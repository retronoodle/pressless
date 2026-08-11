<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

/**
 * ISO `YYYY-MM-DD` date rendered as `<input type="date">`. Persists to
 * `entry_values.value_date`.
 */
final class DateFieldType implements FieldType
{
    private const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function key(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return 'Date';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => null,
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $default = $field['default'] ?? null;
        if ($default === null || $default === '') {
            $normalized['default'] = null;
        } elseif (is_string($default) && preg_match(self::ISO_PATTERN, $default) === 1) {
            $normalized['default'] = $default;
        } else {
            $normalized['default'] = null;
        }
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $required = (bool) ($field['required'] ?? false);
        $string = is_scalar($value) ? trim((string) $value) : '';

        if ($string === '') {
            if ($required) {
                return ['This field is required.'];
            }
            return [];
        }

        if (preg_match(self::ISO_PATTERN, $string) !== 1) {
            return ['Must be an ISO date (YYYY-MM-DD).'];
        }

        if (!$this->isRealDate($string)) {
            return ['Must be a real calendar date.'];
        }

        return [];
    }

    public function databaseColumns(): array
    {
        return ['value_date'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        $string = is_scalar($value) ? trim((string) $value) : '';
        if ($string === '' || preg_match(self::ISO_PATTERN, $string) !== 1) {
            return ['value_date' => null];
        }
        return ['value_date' => $string];
    }

    public function bindForRead(array $row): mixed
    {
        if (!array_key_exists('value_date', $row) || $row['value_date'] === null || $row['value_date'] === '') {
            return null;
        }
        return (string) $row['value_date'];
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $required = (bool) ($field['required'] ?? false);
        $current = is_scalar($value) ? (string) $value : '';

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>'
            . '<input type="date" id="' . self::e($key) . '" name="fields[' . self::e($key) . ']"'
            . ' value="' . self::e($current) . '"'
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

    private function isRealDate(string $iso): bool
    {
        $parts = explode('-', $iso);
        if (count($parts) !== 3) {
            return false;
        }
        $year = (int) $parts[0];
        $month = (int) $parts[1];
        $day = (int) $parts[2];
        return checkdate($month, $day, $year);
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
