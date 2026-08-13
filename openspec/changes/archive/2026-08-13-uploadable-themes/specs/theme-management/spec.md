## ADDED Requirements

### Requirement: Admin can upload a theme ZIP
The system SHALL allow an authenticated admin to upload a ZIP file containing a theme via the admin console. The system SHALL reject the upload without extracting any files if the ZIP fails any of: real-content MIME check (not client-supplied), configured size limit, entry-count cap, presence of an entry name containing `..`, an absolute path, or resolving outside the target theme directory, or presence of a symlink entry.

#### Scenario: Valid theme ZIP is uploaded
- **WHEN** an admin uploads a ZIP containing exactly one top-level folder with `base.twig`, `home.twig`, `collection.twig`, and `entry.twig` present, under the configured size and entry-count limits
- **THEN** the system extracts it to `themes/<slug>/` (slug derived from the top-level folder name) and lists it as an installed, inactive theme

#### Scenario: Oversized ZIP is rejected
- **WHEN** an admin uploads a ZIP exceeding the configured maximum size
- **THEN** the system rejects the upload with an error and does not open or extract the archive

#### Scenario: ZIP with unsafe entry names is rejected
- **WHEN** an admin uploads a ZIP containing an entry whose name contains `..`, is an absolute path, or is a symlink
- **THEN** the system rejects the entire upload, writes no files under `themes/`, and shows an error

#### Scenario: ZIP missing required templates is rejected
- **WHEN** an admin uploads a ZIP whose top-level folder is missing one or more of `base.twig`, `home.twig`, `collection.twig`, `entry.twig`
- **THEN** the system rejects the upload and the error names which required templates are missing

#### Scenario: ZIP with a slug that already exists is rejected
- **WHEN** an admin uploads a ZIP whose derived slug matches an existing theme (an existing `themes/<slug>/` directory or a database record)
- **THEN** the system rejects the upload with an error indicating the theme already exists, and does not modify the existing theme's files or database record

#### Scenario: ZIP with no single top-level folder is rejected
- **WHEN** an admin uploads a ZIP that has files at its root, or more than one top-level folder
- **THEN** the system rejects the upload with an error explaining a single theme folder is required

### Requirement: Optional theme manifest provides display metadata
The system SHALL parse an optional `theme.json` file at the root of the uploaded theme folder for `name`, `version`, and `author` string fields. When absent or unparsable, the system SHALL derive a display name from the theme's slug and leave `version`/`author` empty, without failing the upload.

#### Scenario: Manifest present and valid
- **WHEN** an uploaded theme's ZIP contains a `theme.json` with `name`, `version`, and `author` fields
- **THEN** the installed theme's listed name, version, and author reflect the manifest's values

#### Scenario: Manifest absent
- **WHEN** an uploaded theme's ZIP contains no `theme.json`
- **THEN** the upload still succeeds and the theme is listed using a name derived from its slug

#### Scenario: Manifest malformed
- **WHEN** an uploaded theme's ZIP contains a `theme.json` that is not valid JSON or has non-string values for `name`/`version`/`author`
- **THEN** the upload still succeeds, using the slug-derived name as fallback, and no error is raised for the malformed manifest

### Requirement: Admin can list installed themes
The system SHALL show all installed themes in the admin console, indicating which one is currently active.

#### Scenario: Themes listed with active indicator
- **WHEN** an admin views the themes admin page
- **THEN** every installed theme is listed with its name, slug, version, and author, and the currently active theme is visibly marked as active

### Requirement: Admin can activate an installed theme
The system SHALL allow an admin to activate any installed, inactive theme. Activation SHALL take effect for subsequent requests without requiring a redeploy, and SHALL ensure exactly one theme is marked active at a time.

#### Scenario: Activating a different theme
- **WHEN** an admin activates a theme that is not currently active
- **THEN** that theme becomes the sole active theme, the previously active theme becomes inactive, and subsequent page renders use the newly active theme's templates and assets

### Requirement: Active theme cannot be deleted
The system SHALL prevent deletion of the currently active theme, both from the admin UI and if a delete is attempted directly against the underlying operation.

#### Scenario: Attempting to delete the active theme
- **WHEN** an admin attempts to delete the currently active theme
- **THEN** the system rejects the deletion with an error and the theme remains installed and active

### Requirement: Admin can delete a non-active theme
The system SHALL allow an admin to delete any installed theme that is not currently active, removing both its database record and its files under `themes/<slug>/`.

#### Scenario: Deleting an inactive theme
- **WHEN** an admin deletes a theme that is not currently active
- **THEN** the theme's database record is removed and its `themes/<slug>/` directory no longer exists

### Requirement: Active theme resolution falls back gracefully
The system SHALL resolve the active theme by checking the database first, falling back to the configured `theme.active` value if the database has no active theme, the database is unreachable, or the database's active theme's directory no longer exists on disk. The system SHALL NOT return a server error solely because of this fallback.

#### Scenario: Database active theme's directory is missing
- **WHEN** the database records an active theme whose `themes/<slug>/` directory does not exist on disk
- **THEN** the system falls back to the theme configured via `theme.active` in configuration, if that directory exists, without returning a server error

#### Scenario: Database is unreachable
- **WHEN** the database cannot be queried for the active theme
- **THEN** the system falls back to the theme configured via `theme.active` in configuration, if that directory exists, without returning a server error
