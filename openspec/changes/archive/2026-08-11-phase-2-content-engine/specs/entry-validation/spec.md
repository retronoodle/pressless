## ADDED Requirements

### Requirement: Per-field-type server-side validation

The entry create and edit handlers SHALL validate every submitted field value through its registered field type's `validate()` method before any persistence occurs. Validation failures SHALL be collected by field key and SHALL cause the form to be re-rendered with the submitted values and inline error messages.

#### Scenario: Required text field

- **WHEN** an entry form is submitted with an empty value for a required `text` field
- **THEN** validation fails with a field-scoped error, no entry row is written, and the form is re-rendered with the error displayed next to the field

#### Scenario: Multiple field errors

- **WHEN** an entry form is submitted with errors in more than one field
- **THEN** all errors are reported on the re-rendered form and the entry is not persisted

#### Scenario: Optional field with empty value

- **WHEN** an entry form is submitted with an empty value for a non-required field
- **THEN** validation succeeds and the value is persisted as null without error

### Requirement: Validation rules per field type

Each built-in field type SHALL define validation rules consistent with its kind:

- `text` and `richtext` SHALL enforce a maximum length when configured and reject empty input when marked required.
- `number` SHALL require a numeric value, optionally bounded by a minimum and maximum.
- `boolean` SHALL accept only the canonical boolean representations sent by the form (for example `0`/`1`, `true`/`false`, or absent).
- `date` SHALL require a parseable date in the configured format (ISO `YYYY-MM-DD` by default).
- `select` SHALL require the submitted value to be one of the configured options.
- `media` and `relation` SHALL accept a placeholder value (the integer `0` or absent) until their picker UIs land in later phases and SHALL validate the placeholder as a valid input.

#### Scenario: Number out of range

- **WHEN** a `number` field is configured with a maximum and the submitted value exceeds it
- **THEN** validation fails with a field-scoped error indicating the maximum

#### Scenario: Date in wrong format

- **WHEN** a `date` field configured for ISO format receives a value that does not parse
- **THEN** validation fails with a field-scoped error indicating the expected format
