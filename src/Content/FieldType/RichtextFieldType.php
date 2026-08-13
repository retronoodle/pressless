<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

use Stead\Content\Html\RichtextSanitizer;

/**
 * Rich-text field rendered as a Tiptap WYSIWYG editor in the admin form.
 * Persists sanitized HTML to `entry_values.value_text`.
 *
 * Lifecycle:
 *   - `bindForWrite()` runs the submitted value through {@see RichtextSanitizer}
 *     so the database only ever holds allowlisted HTML.
 *   - `validate()` runs sanitization first, then checks the configured
 *     `max_length` against the *extracted plain text* length (not the HTML
 *     markup length), and treats empty extracted text as "required fails".
 *   - `renderForm()` emits a container `<div>` plus a hidden input; the
 *     `public/assets/js/admin/richtext-editor.js` script mounts Tiptap on
 *     the div and keeps the hidden input in sync with the editor's HTML
 *     output on every change and on form submit.
 *
 * The sanitizer is optional so the field type can be constructed without
 * wiring (tests, headless contexts). When omitted, a default instance is
 * created on first use.
 */
final class RichtextFieldType implements FieldType
{
    private const TOOLBAR = 'bold,italic,h2,h3,ul,ol,blockquote,link,image';

    private readonly RichtextSanitizer $sanitizer;

    public function __construct(?RichtextSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? new RichtextSanitizer();
    }

    public function key(): string
    {
        return 'richtext';
    }

    public function label(): string
    {
        return 'Rich text';
    }

    public function schemaDefaults(): array
    {
        return [
            'required' => false,
            'default' => null,
            'max_length' => null,
        ];
    }

    public function normalize(array $field): array
    {
        $normalized = $this->preserveIdentity($field);
        $normalized['required'] = (bool) ($field['required'] ?? false);
        $normalized['default'] = $field['default'] ?? null;
        $max = $field['max_length'] ?? null;
        $normalized['max_length'] = is_int($max) && $max > 0 ? $max : null;
        return $normalized;
    }

    public function validate(mixed $value, array $field): array
    {
        $required = (bool) ($field['required'] ?? false);
        $maxLength = $field['max_length'] ?? null;

        $sanitized = $this->sanitizer->sanitize($this->asString($value));
        $plainTextLength = $this->sanitizer->plainTextLength($sanitized);

        if ($required && $plainTextLength === 0) {
            return ['This field is required.'];
        }

        if (is_int($maxLength) && $maxLength > 0 && $plainTextLength > $maxLength) {
            return [sprintf('Must be at most %d characters.', $maxLength)];
        }

        return [];
    }

    public function databaseColumns(): array
    {
        return ['value_text'];
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        $sanitized = $this->sanitizer->sanitize($this->asString($value));
        return ['value_text' => $sanitized === '' ? null : $sanitized];
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
        $current = $this->asString($value);

        $html = '<label for="' . self::e($key) . '__editor">' . self::e($label) . '</label>'
            . '<div class="stead-richtext"'
            . ' data-stead-richtext="' . self::e($key) . '"'
            . ' data-stead-richtext-toolbar="' . self::e(self::TOOLBAR) . '"'
            . ($required ? ' data-stead-richtext-required="1"' : '')
            . '>'
            . '<div class="stead-richtext__editor" id="' . self::e($key) . '__editor"></div>'
            . '<input type="hidden"'
            . ' name="fields[' . self::e($key) . ']"'
            . ' id="' . self::e($key) . '"'
            . ' value="' . self::e($current) . '">'
            . '</div>';

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
