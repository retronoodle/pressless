## MODIFIED Requirements

### Requirement: Validation rules per field type

Each built-in field type SHALL define validation rules consistent with its kind:

- `text` SHALL enforce a maximum length when configured and reject empty input when marked required.
- `richtext` SHALL sanitize submitted HTML against its toolbar's allowed tag/attribute list before persistence, SHALL enforce a maximum length (measured on the extracted plain-text content, not the HTML markup) when configured, and SHALL reject empty input when marked required.
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

#### Scenario: Richtext required field rejects empty HTML

- **WHEN** a required `richtext` field is submitted with HTML that sanitizes and extracts to empty text (for example `<p></p>` or only disallowed tags)
- **THEN** validation fails with a field-scoped "required" error and the value is not persisted

#### Scenario: Richtext max length counts visible text, not markup

- **WHEN** a `richtext` field configured with `max_length: 100` is submitted with sanitized HTML whose extracted plain-text content is 90 characters but whose HTML markup is 300 characters
- **THEN** validation succeeds, since the configured limit is measured against extracted text length
