<?php

declare(strict_types=1);

namespace Pressless\Content\FieldType;

/**
 * Placeholder cross-collection reference. The picker UI is not wired until
 * Phase 5; for now the field round-trips
 * `{"target_collection": null, "target_id": 0}` through `value_json` and
 * renders a clearly labeled placeholder.
 */
final class RelationFieldType implements FieldType
{
    public function key(): string
    {
        return 'relation';
    }

    public function label(): string
    {
        return 'Relation';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => ['target_collection' => null, 'target_id' => 0],
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $normalized['default'] = $this->asRelation($field['default'] ?? []);
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $required = (bool) ($field['required'] ?? false);
        $rel = $this->asRelation($value);

        if ($rel['target_id'] === 0 || $rel['target_collection'] === null) {
            if ($required) {
                return ['A target entry is required.'];
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
        $rel = $this->asRelation($value);
        return ['value_json' => (string) json_encode($rel, JSON_UNESCAPED_SLASHES)];
    }

    public function bindForRead(array $row): mixed
    {
        if (!array_key_exists('value_json', $row) || $row['value_json'] === null) {
            return null;
        }
        $decoded = json_decode((string) $row['value_json'], true);
        return $this->asRelation($decoded);
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $rel = $this->asRelation($value);
        $collection = (string) ($rel['target_collection'] ?? '');
        $targetId = (int) $rel['target_id'];

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>'
            . '<p class="field-placeholder" data-field="' . self::e($key) . '">'
            . 'Relation picker not yet wired.</p>'
            . '<input type="hidden" name="fields[' . self::e($key) . '][target_collection]" value="' . self::e($collection) . '">'
            . '<input type="hidden" name="fields[' . self::e($key) . '][target_id]" value="' . $targetId . '">'
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
     * @return array{target_collection: string|null, target_id: int}
     */
    private function asRelation(mixed $value): array
    {
        if (!is_array($value)) {
            return ['target_collection' => null, 'target_id' => 0];
        }
        $collection = $value['target_collection'] ?? null;
        $targetId = $value['target_id'] ?? 0;
        return [
            'target_collection' => is_string($collection) && $collection !== '' ? $collection : null,
            'target_id' => is_numeric($targetId) ? (int) $targetId : 0,
        ];
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
