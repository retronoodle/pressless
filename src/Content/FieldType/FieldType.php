<?php

declare(strict_types=1);

namespace Pressless\Content\FieldType;

/**
 * The single contract every field type must satisfy.
 *
 * A field type owns the full lifecycle of one field kind: its schema fragment,
 * the value column(s) it persists to, how the value is validated and
 * normalized, and the admin form control. Application code outside the
 * {@see FieldTypeRegistry} MUST NOT branch on field-type identity using
 * instanceof or string equality on the type key.
 *
 * Typical callers are the collection schema validator, the entry save/read
 * pipeline, and the admin form templates.
 */
interface FieldType
{
    /**
     * The short registry key (for example `text`, `number`, `date`).
     */
    public function key(): string;

    /**
     * Human-readable label used in admin UIs (for example `Text`).
     */
    public function label(): string;

    /**
     * Default options for this type. The shape is type-specific; callers
     * merge these with user-supplied options before validation.
     *
     * @return array<string, mixed>
     */
    public function schemaDefaults(): array;

    /**
     * Fills in defaults, normalizes option casing, and returns a fully
     * populated field definition. Implementations MUST NOT mutate the
     * input array.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public function normalize(array $field): array;

    /**
     * Validates a submitted value against the field definition. Returns a
     * list of human-readable error messages; an empty list means the value
     * is acceptable.
     *
     * @param array<string, mixed> $field
     * @return list<string>
     */
    public function validate(mixed $value, array $field): array;

    /**
     * Names of the typed columns this field kind writes to on the
     * `entry_values` table. Implementations return exactly the subset of
     * `value_text`, `value_number`, `value_date`, `value_bool`, `value_json`
     * they populate.
     *
     * @return list<string>
     */
    public function databaseColumns(): array;

    /**
     * Builds the column=>value mapping for a single INSERT/UPDATE on
     * `entry_values`. The repository caller is responsible for adding
     * `entry_id`, `field_key`, `field_type`, and timestamps.
     *
     * @return array<string, mixed>
     */
    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array;

    /**
     * Reconstructs the typed value from a row selected from `entry_values`.
     * Implementations read from the column they returned in
     * {@see databaseColumns()} and return null when the typed column is
     * null.
     *
     * @param array<string, mixed> $row
     */
    public function bindForRead(array $row): mixed;

    /**
     * Renders a fully labeled admin form control, including inline error
     * messages. The returned HTML is safe to insert verbatim into a Twig
     * template with `|raw`; implementations MUST escape every dynamic
     * value with htmlspecialchars.
     *
     * @param array<string, mixed> $field
     * @param list<string>         $errors
     */
    public function renderForm(array $field, mixed $value, array $errors): string;
}
