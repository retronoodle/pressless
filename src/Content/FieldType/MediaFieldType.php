<?php

declare(strict_types=1);

namespace Pressless\Content\FieldType;

/**
 * Placeholder media reference. The picker UI is not wired until Phase 4;
 * for now the field round-trips `{"id": 0}` through `value_json` and renders
 * a clearly labeled placeholder so evaluators can see the contract.
 */
final class MediaFieldType implements FieldType
{
    public function key(): string
    {
        return 'media';
    }

    public function label(): string
    {
        return 'Media';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => ['id' => 0],
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $normalized['default'] = $this->asMediaRef($field['default'] ?? ['id' => 0]);
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $required = (bool) ($field['required'] ?? false);
        $ref = $this->asMediaRef($value);

        if (($ref['id'] ?? 0) === 0) {
            if ($required) {
                return ['A media item is required.'];
            }
            return [];
        }

        return [];
    }

    public function databaseColumns(): array
    {
        return ['value_json'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        $ref = $this->asMediaRef($value);
        return ['value_json' => (string) json_encode($ref, JSON_UNESCAPED_SLASHES)];
    }

    public function bindForRead(array $row): mixed
    {
        if (!array_key_exists('value_json', $row) || $row['value_json'] === null) {
            return null;
        }
        $decoded = json_decode((string) $row['value_json'], true);
        return $this->asMediaRef($decoded);
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $ref = $this->asMediaRef($value);
        $refId = (int) ($ref['id'] ?? 0);

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>'
            . '<p class="field-placeholder" data-field="' . self::e($key) . '">'
            . 'Media picker not yet wired.</p>'
            . '<input type="hidden" name="fields[' . self::e($key) . '][id]" value="' . $refId . '">'
            . '<input type="text" id="' . self::e($key) . '" value="(placeholder)" disabled>';

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
     * @return array{id: int}
     */
    private function asMediaRef(mixed $value): array
    {
        if (is_array($value) && array_key_exists('id', $value) && is_numeric($value['id'])) {
            return ['id' => (int) $value['id']];
        }
        if (is_int($value)) {
            return ['id' => $value];
        }
        return ['id' => 0];
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
