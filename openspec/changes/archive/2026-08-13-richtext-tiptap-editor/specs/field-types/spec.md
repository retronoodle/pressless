## MODIFIED Requirements

### Requirement: Built-in field types

The system SHALL ship the eight built-in field types behind the `FieldType` contract: `text`, `richtext`, `number`, `boolean`, `date`, `select`, `media`, and `relation`. Each SHALL persist its value to the appropriate typed column on `entry_values`, validate input according to its kind, and render a labeled admin form control. The `richtext` field type SHALL render a Tiptap-based WYSIWYG editor exposing a fixed toolbar (bold, italic, headings H2/H3, bullet list, numbered list, blockquote, link, image) rather than a plain `<textarea>`, and SHALL persist the editor's output as sanitized HTML rather than plain text.

#### Scenario: Text field write and read

- **WHEN** an entry containing a `text` field is saved with the value "Hello" and then read back
- **THEN** the value is stored in `entry_values.value_text` and the repository returns "Hello" with no type coercion

#### Scenario: Number field rejects non-numeric input

- **WHEN** a `number` field is submitted with a non-numeric value
- **THEN** validation returns a field-scoped error and the value is not persisted

#### Scenario: Date field accepts ISO date

- **WHEN** a `date` field is submitted with a value matching the ISO date format
- **THEN** validation succeeds and the value is stored in `entry_values.value_date`

#### Scenario: Boolean field normalizes truthy strings

- **WHEN** a `boolean` field is submitted with a truthy checkbox value (for example `1` or `true`)
- **THEN** the value is normalized to a boolean true before persistence

#### Scenario: Select field validates against configured options

- **WHEN** a `select` field is configured with a fixed list of options and a value outside that list is submitted
- **THEN** validation fails with a field-scoped error

#### Scenario: Media field renders a working picker

- **WHEN** the admin renders a form containing a `media` field
- **THEN** the field type renders a control that lets the user pick an existing item from the media library or upload a new one, and stores the selected item's id

#### Scenario: Relation field still renders a placeholder

- **WHEN** the admin renders a form containing a `relation` field
- **THEN** the field type renders a clearly labeled placeholder control that does not pretend to store a real reference until a later phase wires it up

#### Scenario: Media field validates the referenced id exists

- **WHEN** a `media` field is submitted with an id that does not correspond to an existing media item
- **THEN** validation returns a field-scoped error and the value is not persisted

#### Scenario: Richtext field renders the Tiptap editor

- **WHEN** the admin renders a form containing a `richtext` field
- **THEN** the field type renders a Tiptap editor instance with the fixed toolbar (bold, italic, headings, lists, blockquote, link, image) instead of a plain textarea

#### Scenario: Richtext field persists sanitized HTML

- **WHEN** a `richtext` field is submitted with HTML produced by the Tiptap editor (for example `<p><strong>Hello</strong></p>`)
- **THEN** the value is sanitized against the toolbar's allowed tag/attribute list and the sanitized HTML is stored in `entry_values.value_text`

#### Scenario: Richtext field strips disallowed markup on submit

- **WHEN** a `richtext` field is submitted with HTML containing a tag or attribute outside the toolbar's allowlist (for example a `<script>` tag or an inline `onclick` handler)
- **THEN** the disallowed markup is stripped before persistence and no error is raised to the user
