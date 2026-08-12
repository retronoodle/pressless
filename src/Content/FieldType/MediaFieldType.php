<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

use Stead\Media\MediaRepository;

/**
 * Reference to a row in the `media` table. The visible form is a `<select>`
 * sourced from {@see MediaRepository} so an editor can pick any uploaded
 * file. The persisted value is `{"id": int}` stored in `value_json`; the
 * picker writes back that same shape on submit.
 *
 * The repository is optional so the field type can be constructed without
 * a database (tests, headless contexts). Without it the picker renders an
 * empty dropdown and `validate()` skips the existence check.
 */
final class MediaFieldType implements FieldType
{
    public function __construct(private readonly ?MediaRepository $media = null)
    {
    }

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

        if ($this->media !== null && $this->media->find($ref['id']) === null) {
            return ['Selected media item no longer exists.'];
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

        $items = $this->media !== null ? $this->media->all() : [];

        $html = '<label for="' . self::e($key) . '">' . self::e($label) . '</label>';
        $html .= '<select id="' . self::e($key) . '" name="fields[' . self::e($key) . '][id]">';
        $html .= '<option value="0"' . ($refId === 0 ? ' selected' : '') . '>— None —</option>';
        foreach ($items as $item) {
            $selected = $item->id() === $refId ? ' selected' : '';
            $html .= '<option value="' . $item->id() . '"' . $selected . '>'
                . self::e($item->filename())
                . ' (' . self::e($item->mimeType()) . ')</option>';
        }
        $html .= '</select>';
        $html .= '<p class="hint">Upload new files in <a href="/admin/media">Media</a>.</p>';

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
