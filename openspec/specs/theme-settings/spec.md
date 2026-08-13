# Capability: theme-settings

## Purpose

TBD

## Requirements

### Requirement: Theme setting values are stored per theme slug
The system SHALL persist theme setting values keyed by theme slug and setting key, independent of the theme's database id, so values survive a delete-then-re-upload cycle for the same slug. Values SHALL NOT be visible or applied when a different theme is active.

#### Scenario: Value persists across delete and re-upload
- **WHEN** an admin configures a value for a setting key on the active theme, deletes that theme, and re-uploads a theme ZIP whose slug matches the deleted theme and whose manifest still declares that key
- **THEN** the previously configured value is shown as the current value for that key once the re-uploaded theme is active

#### Scenario: Values do not leak across themes
- **WHEN** an admin configures values for theme A, then activates theme B
- **THEN** theme B's settings form and rendered templates show only theme B's own stored values (or its manifest defaults), never theme A's values

### Requirement: Admin can configure the active theme's settings
The system SHALL provide an authenticated, admin-only "Theme Settings" screen at `/admin/theme-settings` that renders a form generated from the active theme's manifest `settings` schema, pre-filled with each key's stored value or, if none is stored, the manifest's declared `default`.

#### Scenario: Form reflects manifest schema
- **WHEN** an admin opens `/admin/theme-settings` while a theme declaring a `settings` schema is active
- **THEN** the form shows one field per declared setting, using the declared `type` and `label`, pre-filled with the stored value or manifest default

#### Scenario: No active theme or no declared settings
- **WHEN** an admin opens `/admin/theme-settings` and the active theme declares no `settings` schema (or has none)
- **THEN** the page shows an explanatory empty state instead of an error

#### Scenario: Admin saves settings
- **WHEN** an admin submits the Theme Settings form with new values
- **THEN** the values are persisted for the active theme's slug and subsequent reads (admin form and rendered templates) return the new values

#### Scenario: Stored value no longer matches select options
- **WHEN** a `select`-type setting's stored value is not among the current manifest's `options` for that key
- **THEN** the form falls back to displaying the manifest's declared `default` for that field rather than an invalid selection

### Requirement: Removed settings keys are retained but dormant
The system SHALL NOT delete a stored theme setting value solely because a re-uploaded theme's manifest no longer declares that key. Dormant values SHALL NOT appear in the admin form or be exposed to Twig while the key is absent from the manifest, but SHALL become available again if a later manifest re-declares the same key.

#### Scenario: Key removed from manifest
- **WHEN** an admin has configured a value for a key, then activates a re-uploaded version of the same theme (same slug) whose manifest no longer declares that key
- **THEN** the key no longer appears in the Theme Settings form or in `theme_settings` in templates, and the previously stored value is not deleted

#### Scenario: Key re-added later
- **WHEN** a key that was previously removed from a theme's manifest is re-declared in a later re-upload of the same slug
- **THEN** the Theme Settings form and template output show the value that was stored before the key was removed

### Requirement: Configured values are available to theme templates
The system SHALL register a `theme_settings` global in the Twig rendering environment, containing the active theme's resolved setting values (stored value, or manifest default when unset), keyed by setting key.

#### Scenario: Template reads a configured value
- **WHEN** a theme template references `{{ theme_settings.hero_image }}` and an admin has configured a value for the `hero_image` key
- **THEN** the rendered page includes the configured value, HTML-escaped per Twig's default autoescaping

#### Scenario: Template reads an unconfigured value
- **WHEN** a theme template references a declared setting key that has no stored value
- **THEN** the rendered page uses the manifest's declared `default` for that key (or an empty string if no default is declared)