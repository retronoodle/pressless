## ADDED Requirements

### Requirement: Entry SEO metadata fields

Every entry SHALL have three optional SEO columns — `meta_title`, `meta_description`, `og_image` (a media reference) — available uniformly regardless of collection, editable as a dedicated section on the entry edit form, and persisted alongside the entry's other metadata. All three SHALL default to empty/`NULL` and are not required to save an entry.

#### Scenario: Save an entry with SEO fields

- **WHEN** an administrator sets `meta_title`, `meta_description`, and picks an `og_image` from the media library on an entry's edit form and saves
- **THEN** the entry is persisted with those three values and reading the entry back returns them

#### Scenario: Save an entry with no SEO fields

- **WHEN** an administrator saves an entry without filling in any SEO field
- **THEN** the entry is persisted with `meta_title`, `meta_description`, and `og_image` empty/`NULL`, and saving does not fail

## MODIFIED Requirements

### Requirement: Entry slug generation and uniqueness

Each collection SHALL declare one field as its `slug_source`. On entry save, the system SHALL compute a slug from that field's value (lowercased, non-alphanumerics replaced with `-`, leading/trailing dashes trimmed, runs collapsed) and SHALL append `-2`, `-3`, … until a free slug is found within that collection. The slug SHALL be stored on `entries.slug` and SHALL be unique per collection. When saving an existing entry changes its slug, the system SHALL create a redirect from the entry's previous public path to its new public path, in the same transaction as the slug update.

#### Scenario: First save with no collision

- **WHEN** an entry is saved whose computed slug does not exist in the same collection
- **THEN** the entry is persisted with that slug

#### Scenario: Collision appends suffix

- **WHEN** two entries in the same collection would compute the same slug
- **THEN** the second entry is persisted with the first available suffixed slug (`-2`, `-3`, …) and earlier entries are unchanged

#### Scenario: Editing unrelated fields keeps the slug

- **WHEN** an existing entry is edited without changing its `slug_source` field
- **THEN** the existing slug is preserved

#### Scenario: Slug change creates a redirect

- **WHEN** an existing published entry's `slug_source` field is edited such that its computed slug changes from `old-slug` to `new-slug`
- **THEN** the entry is persisted with `slug = new-slug` and a redirect is created from the entry's old public path to its new public path

#### Scenario: New entry's first save creates no redirect

- **WHEN** a brand-new entry is saved for the first time
- **THEN** a slug is assigned and no redirect is created, since there is no prior slug to redirect from
