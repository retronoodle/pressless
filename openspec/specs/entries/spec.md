# Capability: entries

## Purpose

TBD

## Requirements

### Requirement: Entry persistence with typed value rows

The system SHALL persist entries by writing one row per field into `entry_values` using the typed columns (`value_text`, `value_number`, `value_date`, `value_bool`, `value_json`) chosen by each field type. Saving an entry SHALL clear its previous `entry_values` rows and write a fresh row per field inside a single transaction. New entries SHALL be persisted with `status = draft` unless created through the publish action; editing an existing entry SHALL NOT change its `status`.

#### Scenario: Save a multi-field entry

- **WHEN** an entry is saved for a collection with three fields (text, number, date)
- **THEN** the `entries` row is created or updated and exactly three `entry_values` rows are written, each using the typed column for its field type

#### Scenario: Resave an edited entry

- **WHEN** an existing entry is edited and saved
- **THEN** the old `entry_values` rows for that entry are removed and the new rows reflect only the current payload, and the entry's `status` is unchanged

#### Scenario: Read an entry by id

- **WHEN** a repository reads an entry by id
- **THEN** the response includes the entry's metadata (including `status`) and a `values` map keyed by `field_key`, with each value reconstructed through the field type's read binding

#### Scenario: New entry defaults to draft

- **WHEN** an administrator creates a new entry via the standard save action
- **THEN** the entry is persisted with `status = draft` and is not visible on any public route

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

### Requirement: Entry SEO metadata fields

Every entry SHALL have three optional SEO columns — `meta_title`, `meta_description`, `og_image` (a media reference) — available uniformly regardless of collection, editable as a dedicated section on the entry edit form, and persisted alongside the entry's other metadata. All three SHALL default to empty/`NULL` and are not required to save an entry.

#### Scenario: Save an entry with SEO fields

- **WHEN** an administrator sets `meta_title`, `meta_description`, and picks an `og_image` from the media library on an entry's edit form and saves
- **THEN** the entry is persisted with those three values and reading the entry back returns them

#### Scenario: Save an entry with no SEO fields

- **WHEN** an administrator saves an entry without filling in any SEO field
- **THEN** the entry is persisted with `meta_title`, `meta_description`, and `og_image` empty/`NULL`, and saving does not fail

### Requirement: Entry CRUD admin surface

The system SHALL provide an authenticated admin surface for entries at `/admin/collections/{slug}/entries` with list, create, edit, delete, publish, and unpublish actions. Each form SHALL be rendered dynamically from the collection's schema using the registered field types and SHALL preserve user input on validation failure. The entry list and edit views SHALL display each entry's current status.

#### Scenario: Entry list per collection

- **WHEN** an authenticated administrator requests `GET /admin/collections/{slug}`
- **THEN** the response lists the collection's entries with their slug, status, and a short preview of the slug-source field

#### Scenario: Create an entry

- **WHEN** an administrator submits `POST /admin/collections/{slug}/entries` with values matching the collection's schema
- **THEN** an entry row and one `entry_values` row per field are persisted with `status = draft`, a unique slug is assigned, and the response redirects to the entry's edit page

#### Scenario: Edit an entry

- **WHEN** an administrator submits `POST /admin/collections/{slug}/entries/{id}` with updated values
- **THEN** the entry's metadata and `entry_values` rows are updated, the slug is recomputed only if the slug source changed, the entry's `status` is unchanged, and the response redirects back to the edit page

#### Scenario: Delete an entry

- **WHEN** an administrator deletes an entry
- **THEN** the entry row and its `entry_values` rows are removed in a single transaction and the response redirects to the entry list

#### Scenario: Unknown collection slug

- **WHEN** an administrator requests an entry surface for a collection slug that does not exist
- **THEN** the response is 404 and no entry data is exposed

#### Scenario: Publish a draft entry

- **WHEN** an administrator submits `POST /admin/collections/{slug}/entries/{id}/publish`
- **THEN** the entry's `status` becomes `published`, `published_at` is set if it was previously unset, the collection's public cache version is bumped, and the response redirects back to the edit page

#### Scenario: Unpublish a published entry

- **WHEN** an administrator submits `POST /admin/collections/{slug}/entries/{id}/unpublish`
- **THEN** the entry's `status` becomes `draft`, `published_at` is left unchanged, the collection's public cache version is bumped, and the response redirects back to the edit page

### Requirement: Paginated collection entry listing

The system SHALL provide a paginated variant of listing entries by collection, returning a bounded page of entries via limit/offset and the total count needed to determine whether further pages exist. This SHALL be used by the public collection listing route in addition to any existing unbounded listing used by the admin surface. Callers SHALL be able to restrict the page to entries of a given status; the public collection listing route SHALL request `published` entries only, and the admin listing SHALL request all statuses.

#### Scenario: First page of a collection with more entries than the page size

- **WHEN** a collection has more entries than one page size and a page is requested with no page number
- **THEN** the response includes the first page of entries and indicates that a next page exists

#### Scenario: Requesting a page beyond the last entry

- **WHEN** a page number is requested that is beyond the collection's last entry
- **THEN** the response is an empty page of entries, not an error

#### Scenario: Collection with fewer entries than the page size

- **WHEN** a collection has fewer entries than one page size
- **THEN** the response includes all of its entries on the first page and indicates no further pages exist

#### Scenario: Public listing excludes drafts

- **WHEN** a collection has both draft and published entries and its paginated page is requested with `status = published`
- **THEN** only published entries are included in the page and its total count
