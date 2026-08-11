<!--
Planned session split for resumable work (added 2026-08-11):
  Session A: Section 1 (schema_change_log migration) + Section 2 (FieldType contract + registry) + Section 3 (eight field types + tests).
  Session B: Section 4 (Collection model + repo) + Section 5 (schema-change helper + idempotence).
  Session C: Section 6 (Entry persistence + slug) + Section 7 (validation pipeline + error rendering).
  Session D: Section 8 (collection admin templates + controllers) + Section 9 (entry admin templates + controllers).
  Session E: Section 10 (admin shell updates + route wiring) + Section 11 (seed extension) + Section 12 (smoke test + PHPUnit/PHPStan pass).
-->

## 1. Schema foundation for the content engine

- [x] 1.1 Add a new SQL migration that creates `schema_change_log` (collection id, previous field-set hash nullable, new field-set hash, applied at timestamp) and a unique index on `(collection_id, new_field_set_hash)`. Add the matching SQLite-portable migration file.
- [x] 1.2 Verify the Phase 1 `entry_values` table supports the typed-uniform layout (`value_text`, `value_number`, `value_date`, `value_bool`, `value_json` plus `field_key`); if any column is missing, add a follow-up migration rather than editing the Phase 1 file.
- [x] 1.3 Add a migration test covering idempotence, the unique `(collection_id, new_field_set_hash)` constraint, and the dependency-safe reset behavior for the new table.

## 2. Field type contract and registry

- [x] 2.1 Define `Pressless\Content\FieldType\FieldType` with the methods named in the design: `key()`, `label()`, `schemaDefaults()`, `normalize()`, `validate()`, `databaseColumns()`, `bindForWrite()`, `bindForRead()`, `renderForm()`. Document each method's contract.
- [x] 2.2 Implement `Pressless\Content\FieldType\FieldTypeRegistry` as a constructor-injected list of registered types, keyed by short name, with `has()`, `get()`, `all()`, and a typed exception for unknown keys.
- [x] 2.3 Wire the registry into the application bootstrap so controllers, repositories, and validators receive it through explicit construction (no service locator).
- [x] 2.4 Add unit tests covering registry lookup, unknown-key failure, and the full method surface through a single fixture that implements `FieldType` for testing.

## 3. Built-in field type implementations

- [x] 3.1 Implement `TextFieldType` with max-length support, required-or-optional, default value, and `<input type="text">` form rendering. Persistence uses `value_text`.
- [x] 3.2 Implement `RichtextFieldType` with max-length support, required-or-optional, default value, and `<textarea>` form rendering. Persistence uses `value_text`.
- [x] 3.3 Implement `NumberFieldType` with optional min/max, required-or-optional, default value, and `<input type="number">` form rendering. Persistence uses `value_number`.
- [x] 3.4 Implement `BooleanFieldType` with optional default, and a labeled checkbox form control. Persistence uses `value_bool`.
- [x] 3.5 Implement `DateFieldType` configured for ISO `YYYY-MM-DD`, required-or-optional, default value, and `<input type="date">` form rendering. Persistence uses `value_date`.
- [x] 3.6 Implement `SelectFieldType` with a fixed options list, required-or-optional, default value, and `<select>` form rendering. Persistence uses `value_text`.
- [x] 3.7 Implement `MediaFieldType` with a placeholder form control (clearly labeled "not yet wired") and persistence as `value_json` containing `{"id": 0}`. Validation accepts the placeholder value.
- [x] 3.8 Implement `RelationFieldType` with a placeholder form control and persistence as `value_json` containing `{"target_collection": null, "target_id": 0}`. Validation accepts the placeholder value.
- [x] 3.9 Add a parameterized test fixture that runs the same validation, normalization, persistence-roundtrip, and form-rendering scenarios across all eight types so per-type coverage stays small.

## 4. Collection model, repository, and schema validator

- [x] 4.1 Implement `Pressless\Content\Collection` as an immutable value object carrying `id`, `slug`, `name`, and `schema` (decoded JSON with `fields` array).
- [x] 4.2 Implement `Pressless\Content\CollectionRepository` with `find($id)`, `findBySlug($slug)`, `all()`, `save(Collection $collection)`, and `delete($id)` using prepared statements against `collections`.
- [x] 4.3 Implement `Pressless\Content\CollectionSchemaValidator` that walks the proposed field set and rejects malformed keys, duplicate keys, unknown types, and per-type options that do not match each type's `schemaDefaults()`.
- [x] 4.4 Add repository and validator tests covering create, update, find-by-slug, slug uniqueness, JSON round-trip, and each validation rejection.

## 5. Schema-change helper

- [x] 5.1 Implement `Pressless\Content\SchemaChangeHelper` with `apply(int $collectionId, array $previousFields, array $newFields): void` that diffs the two field sets, performs the required `entry_values` operations inside a transaction, and records the result in `schema_change_log`.
- [x] 5.2 Implement the diff cases: add (no DDL needed), drop (delete matching `entry_values` rows), rename (`UPDATE entry_values SET field_key = ?`), and type change (delete the typed rows so the old type cannot be read as the new type).
- [x] 5.3 Add idempotence by short-circuiting when the new field-set hash matches the last logged hash for that collection.
- [x] 5.4 Add unit tests for each diff case, idempotence, transaction-rollback on failure, and the schema-change-log row written on each non-no-op edit.

## 6. Entry model, repository, and slug generation

- [x] 6.1 Implement `Pressless\Content\Entry` as a value object carrying `id`, `collectionId`, `slug`, and `values` (map of `field_key` to typed value).
- [x] 6.2 Implement `Pressless\Content\EntryRepository` with `find($id)`, `findByCollectionAndSlug($collectionId, $slug)`, `listByCollection($collectionId)`, `save(Entry $entry, Collection $collection): Entry`, and `delete($id)`.
- [x] 6.3 Implement the entry save flow: clear the entry's previous `entry_values` rows, then write a fresh row per field using each field type's `bindForWrite()`, all inside a single transaction.
- [x] 6.4 Implement the entry read flow: load the entry row, left-join `entry_values` for the entry's id, and reconstruct typed values through each field type's `bindForRead()`.
- [x] 6.5 Implement `Pressless\Content\SlugGenerator` with `generate(string $sourceValue): string` (lowercase, alphanumerics only, runs collapsed) and `uniqueForCollection(string $base, int $collectionId, ?int $excludeEntryId = null): string` that appends `-2`, `-3`, … as needed.
- [x] 6.6 Wire slug generation into `EntryRepository::save` so it runs only when the slug source field changes; existing slugs are preserved on unrelated edits.
- [x] 6.7 Add repository and slug tests covering save, resave, list, delete, slug collision, idempotent re-save, and the slug-preservation behavior on unrelated edits.

## 7. Entry validation pipeline

- [x] 7.1 Implement `Pressless\Content\EntryValidator` with `validate(Collection $collection, array $payload): array` that walks the collection's field set and accumulates field-keyed errors via each field type's `validate()`.
- [x] 7.2 Add a typed result shape that distinguishes "no errors" from "errors grouped by field key" so controllers can render inline messages without branching on shapes.
- [x] 7.3 Add a small template helper or partial that renders a field's label, control, current value, and inline error list consistently across collection and entry forms.
- [x] 7.4 Add validator tests for each built-in field type's rejection cases, the multi-error accumulation behavior, and the no-errors fast path.

## 8. Collection admin controllers and templates

- [x] 8.1 Add the new admin routes for collections (`GET /admin/collections`, `GET/POST /admin/collections/new`, `GET/POST /admin/collections/{slug}/edit`, `POST /admin/collections/{slug}/delete`) to the Phase 1 route table.
- [x] 8.2 Implement `Pressless\Http\Controllers\CollectionAdminController` with thin `index`, `create`, `store`, `edit`, `update`, and `destroy` actions that delegate validation, persistence, and schema-change logic to the dedicated services.
- [x] 8.3 Add Twig templates `templates/admin/collections/{index,form}.twig` rendered through the shared admin layout. The form SHALL add/remove/reorder fields dynamically through repeated form sections rather than JS — server-rendered add/remove via `_add_field`/`_remove_field` form controls is acceptable.
- [x] 8.4 Add controller integration tests covering list rendering, successful create, validation-failure re-render, successful edit with schema change, schema-change rollback on failure, and delete.

## 9. Entry admin controllers and templates

- [x] 9.1 Add the new admin routes for entries (`GET /admin/collections/{slug}`, `GET/POST /admin/collections/{slug}/entries/new`, `GET/POST /admin/collections/{slug}/entries/{id}/edit`, `POST /admin/collections/{slug}/entries/{id}/delete`) to the Phase 1 route table.
- [x] 9.2 Implement `Pressless\Http\Controllers\EntryAdminController` with thin `index`, `create`, `store`, `edit`, `update`, and `destroy` actions that delegate validation and persistence to the entry repository and validator.
- [x] 9.3 Add Twig templates `templates/admin/entries/{index,form}.twig` rendered through the shared admin layout. The form SHALL iterate the collection's field set, calling each field type's `renderForm()`, and SHALL render the slug as a read-only preview once it has been generated.
- [x] 9.4 Add controller integration tests covering list rendering, successful create with auto-slug, validation-failure re-render with preserved values, edit with unchanged slug, edit with slug change, and delete.

## 10. Admin shell updates and route wiring

- [x] 10.1 Update the shared admin layout template to replace the `Collections` placeholder link with an active link to `/admin/collections` and to surface the active collection name when editing one of its entries.
- [x] 10.2 Update the `/admin` dashboard empty state to call out "Create your first collection" with a direct link to `/admin/collections/new` when no collections exist, and to show collection/entry counts otherwise.
- [x] 10.3 Verify that all Phase 2 admin routes are protected by the existing authentication guard and that 404/405 behavior is preserved for unknown paths and unsupported methods.
- [x] 10.4 Add a shell integration test confirming the navigation links render for an authenticated user and that the dashboard empty state renders the correct call-to-action when the collections table is empty.

## 11. Seed extension

- [x] 11.1 Extend the existing seeder so that, after creating the administrator and the documented empty sample collections, it also creates a `posts` collection with `title` (text, required, slug source), `body` (richtext), and `published_at` (date) fields.
- [x] 11.2 Extend the seeder to create three sample entries in `posts` ("Hello, world", "Why a typed CMS", "Field types, in plain English") with deterministic slugs and representative body text.
- [x] 11.3 Confirm idempotence by running the seeder twice and asserting no duplicate rows are created and the second run exits successfully.
- [x] 11.4 Add a seeder test covering the new collection, fields, and entries plus the idempotence property.

## 12. End-to-end verification

- [x] 12.1 Add an evaluator smoke test (or extend the Phase 1 smoke test) covering: fresh reset, migrations, seed, login, create a collection through the admin surface, edit the collection's field set, create an entry, see the entry in the list with the auto-generated slug, and delete the entry.
- [x] 12.2 Run the documented PHPUnit suite and resolve all Phase 2 failures.
- [x] 12.3 Run the documented PHPStan checks at the project's configured level and resolve all Phase 2 failures.
- [x] 12.4 Run the full evaluator sequence manually (`bin/serve --fresh --seed`, login, define Posts, add three entries, see them listed) and capture the result in the change verification record.
