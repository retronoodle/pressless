# Capability: collections

## Purpose

TBD

## Requirements

### Requirement: Collection schema as JSON

The `collections.schema` column SHALL store the collection's field definitions as JSON with the shape `{ fields: [ { key, type, label, required, default, ...type-specific options } ] }`. Saving a collection SHALL validate the JSON against the registered field types and reject malformed field keys, duplicate keys, unknown types, or per-type options that do not match the type's schema defaults.

#### Scenario: Save a valid collection

- **WHEN** an administrator saves a collection with a well-formed field set whose types are all registered
- **THEN** the collection row is created or updated and the JSON is persisted unchanged

#### Scenario: Duplicate field keys

- **WHEN** an administrator saves a collection containing two fields with the same `key`
- **THEN** the save is rejected with a field-scoped error identifying the duplicate key

#### Scenario: Unknown field type

- **WHEN** an administrator saves a collection containing a field with an unregistered `type`
- **THEN** the save is rejected and no collection row is written

### Requirement: Collection CRUD admin surface

The system SHALL provide an authenticated admin surface for collections at `/admin/collections` with list, create, edit, and delete actions. Each form SHALL be rendered from the registered field types and SHALL preserve user input on validation failure.

#### Scenario: Collection list

- **WHEN** an authenticated administrator requests `GET /admin/collections`
- **THEN** the response lists all existing collections with their slug, label, and field count, and links to the create form

#### Scenario: Create a collection

- **WHEN** an administrator submits `POST /admin/collections` with a valid name, slug, and field set
- **THEN** a new collection row is persisted, the schema-change log records the initial field set, and the response redirects to the collection's entry list

#### Scenario: Edit a collection

- **WHEN** an administrator submits `POST /admin/collections/{slug}` with an updated field set
- **THEN** the collection's schema is replaced, the schema-change helper applies any required `entry_values` cleanup or rename, and the schema-change log records the new field set

#### Scenario: Validation error preserves form state

- **WHEN** a collection form submission fails validation
- **THEN** the form is re-rendered with the submitted values and a clear per-field error message

### Requirement: Schema-change helper

The system SHALL provide a schema-change helper that diffs the previous and new field sets when a collection is edited and applies the resulting `entry_values` data changes inside the same transaction as the collection update. The helper SHALL be idempotent across reruns of the same change and SHALL record what it changed in a `schema_change_log` table.

#### Scenario: Drop a field

- **WHEN** an administrator edits a collection and removes a field that has existing entry values
- **THEN** the helper deletes the matching `entry_values` rows for that field key before the transaction commits

#### Scenario: Rename a field key

- **WHEN** an administrator edits a collection and changes a field's `key` but not its `type`
- **THEN** the helper rewrites the `field_key` column on the affected `entry_values` rows without changing their typed values

#### Scenario: Change a field's type

- **WHEN** an administrator edits a collection and changes a field's `type`
- **THEN** the helper removes the existing typed rows for that field so the old typed value cannot be read as the new type

#### Scenario: Schema-change idempotence

- **WHEN** the same collection edit is applied twice without further changes
- **THEN** the second save performs no additional `entry_values` DDL and exits successfully
