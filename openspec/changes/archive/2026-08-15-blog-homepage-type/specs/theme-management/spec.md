## MODIFIED Requirements

### Requirement: Optional theme manifest provides display metadata
The system SHALL parse an optional `theme.json` file at the root of the uploaded theme folder for `name`, `version`, `author` string fields, an optional `homepage_type` string field, and an optional `settings` array. When `name`/`version`/`author` are absent or unparsable, the system SHALL derive a display name from the theme's slug and leave `version`/`author` empty, without failing the upload. When `homepage_type` is absent or is not one of the recognized values (`collection_list`, `static_page`, `blog`), it SHALL be treated as absent (the system falls back to `collection_list` at render time) without failing the upload. Each entry in `settings` SHALL be an object with a `key` (string, unique within the array) and `type` (one of `text`, `textarea`, `boolean`, `select`, `color`, `image`), and MAY include `label`, `default`, and — for `type: select` — `options` (a list of string choices). Entries missing `key` or `type`, or with a `type` outside the allowed set, SHALL be dropped from the parsed schema without failing the upload.

#### Scenario: Manifest present and valid
- **WHEN** an uploaded theme's ZIP contains a `theme.json` with `name`, `version`, and `author` fields
- **THEN** the installed theme's listed name, version, and author reflect the manifest's values

#### Scenario: Manifest absent
- **WHEN** an uploaded theme's ZIP contains no `theme.json`
- **THEN** the upload still succeeds and the theme is listed using a name derived from its slug

#### Scenario: Manifest malformed
- **WHEN** an uploaded theme's ZIP contains a `theme.json` that is not valid JSON or has non-string values for `name`/`version`/`author`
- **THEN** the upload still succeeds, using the slug-derived name as fallback, and no error is raised for the malformed manifest

#### Scenario: Manifest declares a settings schema
- **WHEN** an uploaded theme's ZIP contains a `theme.json` with a `settings` array of valid entries (each with `key` and a supported `type`)
- **THEN** the upload succeeds and the theme's parsed settings schema is available for the admin Theme Settings form and Twig rendering

#### Scenario: Settings entry is invalid
- **WHEN** a `theme.json`'s `settings` array contains an entry missing `key` or `type`, or with an unsupported `type`
- **THEN** the upload still succeeds, that entry is excluded from the parsed schema, and no error is raised

#### Scenario: Manifest declares a homepage type default
- **WHEN** an uploaded theme's ZIP contains a `theme.json` with `homepage_type: "static_page"`
- **THEN** the upload succeeds and the theme's parsed homepage type default is available for homepage resolution when activated

#### Scenario: Manifest declares a blog homepage type default
- **WHEN** an uploaded theme's ZIP contains a `theme.json` with `homepage_type: "blog"`
- **THEN** the upload succeeds and the theme's parsed homepage type default is available for homepage resolution when activated

#### Scenario: Manifest declares an unrecognized homepage type
- **WHEN** an uploaded theme's ZIP contains a `theme.json` with a `homepage_type` value that is not `collection_list`, `static_page`, or `blog`
- **THEN** the upload still succeeds, the homepage type default is treated as absent, and no error is raised