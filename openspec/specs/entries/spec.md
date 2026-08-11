# Capability: entries

## Purpose

TBD

## Requirements

### Requirement: Entry persistence with typed value rows

The system SHALL persist entries by writing one row per field into `entry_values` using the typed columns (`value_text`, `value_number`, `value_date`, `value_bool`, `value_json`) chosen by each field type. Saving an entry SHALL clear its previous `entry_values` rows and write a fresh row per field inside a single transaction.

#### Scenario: Save a multi-field entry

- **WHEN** an entry is saved for a collection with three fields (text, number, date)
- **THEN** the `entries` row is created or updated and exactly three `entry_values` rows are written, each using the typed column for its field type

#### Scenario: Resave an edited entry

- **WHEN** an existing entry is edited and saved
- **THEN** the old `entry_values` rows for that entry are removed and the new rows reflect only the current payload

#### Scenario: Read an entry by id

- **WHEN** a repository reads an entry by id
- **THEN** the response includes the entry's metadata and a `values` map keyed by `field_key`, with each value reconstructed through the field type's read binding

### Requirement: Entry slug generation and uniqueness

Each collection SHALL declare one field as its `slug_source`. On entry save, the system SHALL compute a slug from that field's value (lowercased, non-alphanumerics replaced with `-`, leading/trailing dashes trimmed, runs collapsed) and SHALL append `-2`, `-3`, … until a free slug is found within that collection. The slug SHALL be stored on `entries.slug` and SHALL be unique per collection.

#### Scenario: First save with no collision

- **WHEN** an entry is saved whose computed slug does not exist in the same collection
- **THEN** the entry is persisted with that slug

#### Scenario: Collision appends suffix

- **WHEN** two entries in the same collection would compute the same slug
- **THEN** the second entry is persisted with the first available suffixed slug (`-2`, `-3`, …) and earlier entries are unchanged

#### Scenario: Editing unrelated fields keeps the slug

- **WHEN** an existing entry is edited without changing its `slug_source` field
- **THEN** the existing slug is preserved

### Requirement: Entry CRUD admin surface

The system SHALL provide an authenticated admin surface for entries at `/admin/collections/{slug}/entries` with list, create, edit, and delete actions. Each form SHALL be rendered dynamically from the collection's schema using the registered field types and SHALL preserve user input on validation failure.

#### Scenario: Entry list per collection

- **WHEN** an authenticated administrator requests `GET /admin/collections/{slug}`
- **THEN** the response lists the collection's entries with their slug and a short preview of the slug-source field

#### Scenario: Create an entry

- **WHEN** an administrator submits `POST /admin/collections/{slug}/entries` with values matching the collection's schema
- **THEN** an entry row and one `entry_values` row per field are persisted, a unique slug is assigned, and the response redirects to the entry's edit page

#### Scenario: Edit an entry

- **WHEN** an administrator submits `POST /admin/collections/{slug}/entries/{id}` with updated values
- **THEN** the entry's metadata and `entry_values` rows are updated, the slug is recomputed only if the slug source changed, and the response redirects back to the edit page

#### Scenario: Delete an entry

- **WHEN** an administrator deletes an entry
- **THEN** the entry row and its `entry_values` rows are removed in a single transaction and the response redirects to the entry list

#### Scenario: Unknown collection slug

- **WHEN** an administrator requests an entry surface for a collection slug that does not exist
- **THEN** the response is 404 and no entry data is exposed
