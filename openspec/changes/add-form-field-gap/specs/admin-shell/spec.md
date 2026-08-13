## ADDED Requirements

### Requirement: Consistent gap between form fields
Admin form fields SHALL be separated from one another by a token-driven vertical gap so the form reads as comfortably spaced across all rows, not just inside each individual control. The gap SHALL be the same between consecutive fields regardless of whether the row markup is a wrapper-`<label>`, a `<p>`, or a `<fieldset>`, and SHALL apply at every nesting level (form, nested fieldset) that contains form rows. The submit row SHALL remain flush with the form's last field (no trailing gap below it).

#### Scenario: Wrapper-label forms have consistent gap
- **WHEN** an authenticated administrator views an admin form whose fields are rendered as `<label>` wrappers (e.g. settings, mail settings, redirects, invites, users, backups)
- **THEN** each field has the same vertical separation from the next as every other field in that form

#### Scenario: Paragraph-row forms have the same gap
- **WHEN** an authenticated administrator views an admin form whose fields are rendered as `<p>` rows (e.g. collections top fields, media, themes)
- **THEN** the vertical separation between consecutive rows matches the separation used by wrapper-label forms

#### Scenario: Nested fieldset rows have the same gap
- **WHEN** an authenticated administrator views an admin form with fields nested inside a `<fieldset>` (e.g. entries SEO section, collections schema)
- **THEN** the vertical separation between consecutive fields inside the fieldset matches the separation used at the form's top level

#### Scenario: Submit row stays flush with last field
- **WHEN** an authenticated administrator views any admin form
- **THEN** the submit control does not have a trailing vertical gap below it (the gap lives between fields, not after the last one)

#### Scenario: Gap value comes from the shared spacing token
- **WHEN** future spacing work touches the gap between form fields
- **THEN** the value is chosen from the shared `--stead-space-*` token scale and not introduced as a per-rule hardcoded value
