# Capability: settings

## Purpose

TBD

## Requirements

### Requirement: Single-row site settings storage

The system SHALL persist site-wide settings (`site_name`, `timezone`, `date_format`) in a single-row `settings` table, following the existing single-row pattern used for mail settings. Reading settings when no row exists SHALL return sane defaults (empty site name, `UTC` timezone, a default date format) rather than an error.

#### Scenario: Read settings before any save

- **WHEN** an administrator requests the settings screen before ever saving settings
- **THEN** the response shows default values (`UTC` timezone, default date format, empty site name) and does not error

#### Scenario: Save settings

- **WHEN** an administrator submits a new site name, timezone, and date format
- **THEN** the single settings row is upserted with the new values and subsequent reads return them

### Requirement: Settings admin screen

The system SHALL provide an authenticated, admin-only screen at `/admin/settings` to view and edit `site_name`, `timezone`, and `date_format`. Validation failures SHALL redisplay the form with the submitted values and an error message, without saving.

#### Scenario: Admin views and updates settings

- **WHEN** an authenticated administrator opens `/admin/settings`, changes the site name, and submits
- **THEN** the response redirects back to `/admin/settings` and the updated site name is displayed

#### Scenario: Non-admin cannot access settings

- **WHEN** an authenticated non-admin user requests `/admin/settings`
- **THEN** the request is rejected the same way other admin-only screens reject non-admins

#### Scenario: Invalid timezone rejected

- **WHEN** an administrator submits a timezone value that is not a valid identifier
- **THEN** the form is redisplayed with the submitted values and a validation error, and the settings row is not changed