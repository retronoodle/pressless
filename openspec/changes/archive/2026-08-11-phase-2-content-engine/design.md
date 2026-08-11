## Context

Phase 1 delivered the runtime, configuration, database foundation, authentication, and an empty authenticated admin shell. The Phase 1 schema already includes `collections`, `entries`, `entry_values`, and `revisions` tables and a JSON `schema` column on `collections` for storing typed field definitions — but the application layer that uses them does not exist yet. Phase 2 is the first phase where the admin actually edits content: a non-developer defines a collection's fields, saves entries into typed value rows, and sees them listed in the admin.

The content engine must:
- Stay framework-free. New code lives under `Pressless\Content\` and `Pressless\Http\Controllers\` (following the Phase 1 layout), with explicit service construction and no ORM, admin kit, or JS framework.
- Preserve the Phase 1 boundaries. Configuration, the PDO wrapper, the custom router, the admin shell, and the seeder are not rewritten — they gain the smallest possible surface to host collections and entries.
- Produce a stable contract for Phase 3. Public site rendering depends on collections having a slug, entries being fetchable by slug, and field values being typed and queryable.
- Treat the eight field types uniformly. Each field type is a single class implementing one contract so the collection model, entry form, validation pipeline, and persistence layer never branch on the field type's identity.

The principal design pressure is that the `entry_values` table is shared across all field types. We need a column strategy that lets eight heterogeneous value shapes coexist without an ORM and without paying for sparse columns on every row.

## Goals / Non-Goals

**Goals:**

- Define a `FieldType` contract that fully describes a field's schema fragment, persistence, validation, and admin form rendering, and ship the eight required implementations behind it.
- Persist a collection's field set as JSON on `collections.schema` and validate it against the registered field types at save time.
- Store entry values in `entry_values` with a column strategy that is uniform across field types but loses no type information, so repositories can read by collection without knowing which field is which.
- Provide admin CRUD for collections (list, create, edit, delete) and entries (list per collection, create, edit, delete) with dynamic forms generated from the collection's schema.
- Generate and enforce unique per-collection entry slugs so entries are addressable for Phase 3.
- Add a schema-change helper that diffs the previous and new field sets on collection edit and applies the resulting `entry_values` DDL inside the same transaction, with bookkeeping that survives crashes.
- Extend `--seed` to create a starter "Posts" collection with three fields and three sample entries.
- Cover the content path end-to-end: define Posts → add three entries → see them listed in admin.

**Non-Goals:**

- Public site rendering, themes, or URLs under `/posts/{slug}` — those land in Phase 3.
- Media uploads, image transforms, or the `media` field type's library picker — the field type is wired in but its picker UI is empty until Phase 4.
- Revisions, publish/unpublish, draft state — `entries.status` is not added in Phase 2; entries are simply present or absent. Phase 4 owns status and revisions.
- Roles, per-collection permissions, ownership scoping — Phase 5.
- A plugin registry, custom field type registration, or raw-SQL escape hatch — Phase 8. Phase 2 ships only the eight built-in field types and registers them through application code, not through plugin manifests.
- A block builder, drag-and-drop reordering, or any client-side rich-text editor heavier than a plain textarea. Richtext is rendered as `<textarea>` with plain text in Phase 2; a richer editor is a Phase 7 polish concern.
- Multi-site, headless/JSON API, comments, SEO automation, or installer/distribution work.

## Decisions

### Make `FieldType` a single contract that covers schema, persistence, validation, and rendering

`Pressless\Content\FieldType\FieldType` exposes: `key()`, `label()`, `schemaDefaults()`, `validate(mixed $value, array $field): array`, `normalize(array $field): array`, `databaseColumns(): array`, `bindForWrite(int $entryId, string $fieldKey, mixed $value): array`, `bindForRead(array $row): mixed`, and `renderForm(array $field, mixed $value, array $errors): string`. A central `FieldTypeRegistry` holds the eight built-in types keyed by short name (`text`, `richtext`, `number`, `boolean`, `date`, `select`, `media`, `relation`) and is the only place Phase 2 code consults to translate a field definition into DDL, validation, or form HTML.

Alternatives considered: (a) separate `FieldSchema`, `FieldValidator`, `FieldPersister`, `FieldRenderer` interfaces per concern — rejected because eight types × four concerns is 32 implementations and forces controllers to know which concern they want. (b) Convention-based reflection on a `FieldType` annotation — rejected as magical and untestable. The single-contract design is honest about the symmetry between form shape, persistence shape, and validation shape, and lets a future plugin system register one class per new field type without changing the registry.

### Use a typed-but-uniform `entry_values` row layout

`entry_values` stores one row per `(entry_id, field_key)` with `value_text`, `value_number`, `value_date`, `value_bool`, and `value_json` columns. Each `FieldType` writes to exactly the column matching its kind (text/richtext/select labels → `value_text`, number → `value_number`, date → `value_date`, boolean → `value_bool`, media/relation → `value_json` containing the ID or `{id, label}`). Read-side joins reconstruct typed values by calling the type's `bindForRead` on the row. The `(entry_id, field_key)` pair is unique. Nullable columns mean the table does not impose a wide sparse shape on MySQL/SQLite.

Alternatives considered: (a) a single JSON `value` column — rejected because it loses type information that Phase 3 (sorting, filtering, indexing) and Phase 5 (ownership queries) need. (b) one wide column per field — rejected because schema changes become expensive and the table shape leaks every collection's fields. The typed-uniform layout gives per-field indexing where it matters (the unique `(entry_id, field_key)` plus a secondary index on `value_text` for slug lookups and on `value_number`/`value_date` for future sorting) while keeping every collection in the same table.

### Persist collection schemas as JSON, validated at save time

`collections.schema` stores a JSON object shaped `{ fields: [ { key, type, label, required, default, ...type-specific options } ] }`. A `CollectionSchemaValidator` walks the array and rejects unknown `type` values, duplicate `key`s, malformed `key`s (must match `^[a-z][a-z0-9_]*$`), and any per-type options that don't match that type's `schemaDefaults()` shape. The admin collection edit form is generated from this schema using the same `FieldType` contract, so adding a field to a collection is the same operation as adding a field to an entry.

Alternatives considered: per-field columns on the `collections` table — rejected because it would re-implement `entry_values` one level up and make Phase 8 plugin field types painful. The JSON-on-the-row approach is the same pattern Phase 1 deliberately preserved.

### Diff field sets on collection edit and apply `entry_values` DDL atomically

When a collection is saved, the new field set is diffed against the previous one. The diff produces a list of `entry_values` column-shape changes: adds (new field → no DDL needed, the row layout is uniform), drops (removed field → delete rows where `field_key = <old>`), renames (`field_key` change → `UPDATE entry_values SET field_key = ? WHERE field_key = ?` for affected entries), and type changes (the field is re-bound to a different `FieldType` → existing rows are deleted so the old typed value cannot leak back as a different type). The diff runs inside the same transaction as the collection update so a partial schema change cannot leave the table mid-state.

Because the column layout of `entry_values` itself does not change between field types, the only DDL ever applied is the `(entry_id, field_key)` rewrite for renames and a row cleanup for drops. No `ALTER TABLE` is needed in the common case; when the design does need one (e.g., a future migration adds a new typed column), the helper records the DDL it executed in a `schema_change_log` table so reruns are idempotent.

Alternatives considered: (a) treat schema changes as a manual migration the developer writes — rejected because the contract is supposed to be editable by non-developers. (b) snapshot the schema into a `collection_versions` table and rebuild `entry_values` rows from the latest snapshot — rejected because it loses entry-level history and complicates Phase 4 revisions.

### Generate entry slugs from a configurable source field with per-collection uniqueness

Each collection declares one field as its `slug_source` (defaults to the first text-like field if none is chosen). On entry save, the slug is the source value lowercased, non-alphanumerics replaced with `-`, leading/trailing dashes trimmed, and collapsed. If the resulting slug already exists for another entry in the same collection, `-2`, `-3`, … is appended until a free slug is found. Slugs are stored on `entries.slug` as `VARCHAR(191)` with a unique index per collection. Slug is regenerated only when the source field changes; subsequent saves of unrelated fields keep the existing slug.

Alternatives considered: (a) auto-increment numeric IDs as public slugs — rejected because the PRD calls out human-readable URLs and Phase 3 needs slugs to render under `/posts/{slug}`. (b) UUIDs — rejected for the same readability reason.

### Build admin forms server-side from the collection schema

The collection edit form and the entry edit form are both rendered by walking the field set in order, calling each `FieldType`'s `renderForm()`, and concatenating. Field-scoped errors are passed in as `{ fieldKey: [messages] }` and rendered inline beside the control. There is no client-side validation, no JS framework, and no AJAX; the form posts a regular HTTP request to a regular handler. This matches the Phase 1 calm-admin principle and keeps Phase 2's surface testable without a browser.

Alternatives considered: (a) progressive enhancement with vanilla JS for instant per-field validation — punted to Phase 7 polish. (b) rich-text editor for the `richtext` field — punted to Phase 7; Phase 2 ships a plain `<textarea>`.

### Validate at the boundary, persist through a single repository call

The entry create/edit handler:
1. Loads the collection and its field set.
2. For each field, pulls the raw form value, runs the field type's `validate()`, accumulates errors by `field_key`.
3. If any errors, re-renders the form with values and errors preserved.
4. Otherwise, computes or reuses the slug, opens a transaction, deletes the entry's existing `entry_values` rows, writes fresh ones via each field type's `bindForWrite()`, and commits.

This keeps the controller thin (orchestration only), puts all per-type knowledge behind the field type, and makes the entry repository a single `save($collection, $payload): Entry` call from the controller's point of view.

Alternatives considered: a per-type controller — rejected because each admin surface would need eight subclasses. A single generic `FieldType` registry dispatch is the right level of polymorphism for eight types and stays extensible to plugin field types in Phase 8.

### Extend the route table, not the router

The Phase 1 custom matcher already supports `{name}` parameter extraction. Phase 2 only adds more routes; it does not change the matcher or the front controller. New routes follow the existing convention:

- `GET  /admin/collections` — collection list
- `GET  /admin/collections/new` — collection create form
- `POST /admin/collections` — collection create handler
- `GET  /admin/collections/{slug}/edit` — collection edit form
- `POST /admin/collections/{slug}` — collection edit handler (with a `_method=delete` override or a separate `POST /admin/collections/{slug}/delete` — chosen at implementation time, whichever keeps the route table tidy)
- `GET  /admin/collections/{slug}` — entry list for that collection
- `GET  /admin/collections/{slug}/entries/new` — entry create form
- `POST /admin/collections/{slug}/entries` — entry create handler
- `GET  /admin/collections/{slug}/entries/{id}/edit` — entry edit form
- `POST /admin/collections/{slug}/entries/{id}` — entry edit/delete handler

All admin routes sit behind the existing Phase 1 authentication guard. Public routes for entries (`/posts/{slug}` etc.) are explicitly out of scope for Phase 2.

### Wire the Phase 1 admin shell to host collections and entries

The shell template's navigation placeholders gain two active links: `Collections` and `Settings` (settings remains a placeholder for now). The empty-state on `/admin` is replaced with a short explanation plus a "Create your first collection" call to action that links to `/admin/collections/new`. The collection list view replaces the placeholder text on the previous Collections nav entry.

### Extend the seeder, don't replace it

`bin/serve --seed` continues to create the development administrator and the empty `collections` rows the Phase 1 seeder shipped. Phase 2 adds a single new seeder step that creates a `posts` collection with `{ title: text(required), body: richtext, published_at: date }` and three sample entries titled "Hello, world", "Why a typed CMS", and "Field types, in plain English". The seeder remains deterministic, idempotent (lookup-by-slug before insert), and production-refused.

## Risks / Trade-offs

- [Per-type JSON-typed value rows make ad-hoc SQL awkward] → Phase 3 and later phases read through the repository, not raw SQL, and the repository's contract is the only supported entry-point for fetching entries.
- [Renaming a field key deletes no user-visible data, but renaming a field type does discard the typed value] → Documented in the collection edit form near the type selector; later phases can add a "convert" path if it becomes a real problem.
- [Slug collisions on identical titles append `-2`, `-3` indefinitely] → Acceptable for Phase 2; the unique index keeps it safe, and Phase 5 can add a "set custom slug" override per entry if needed.
- [Server-rendered forms mean a single error forces a full page round-trip] → Acceptable; matches the calm-admin principle and stays in line with no-JS-framework. Phase 7 may add progressive enhancement.
- [Schema-change diff runs on every collection save and writes a row to `schema_change_log`] → The log is append-only and small in practice (one row per save); cleanup is out of scope for Phase 2.
- [The `media` and `relation` field types have placeholder admin controls until Phase 4/5] → Field types render an explicit "not yet wired in admin" control so evaluators see the contract without being misled; they validate but cannot store a real reference until those phases land.
- [Eight field types × one contract = eight implementations to test] → Worth it; tests can use one parameterized fixture per type and cover the same validation/persistence/rendering scenarios, keeping per-type test surface small.

## Migration Plan

1. Pull Phase 2 source into the existing repository layout (`src/Content/`, `src/Http/Controllers/`, `templates/admin/`, `tests/`). No new Composer dependencies.
2. Add the `schema_change_log` table via a new migration file (versioned alongside the Phase 1 migrations; the runner already supports incremental application).
3. Run `bin/migrate` (or `bin/serve --fresh`) to apply the new migration on an evaluator database.
4. Run `bin/serve --seed` to create the sample Posts collection and entries.
5. Manually exercise the path: log in → create a collection → add a field → save → add three entries → see them in the entry list → edit one → see slug, values, and inline errors.
6. Run the existing PHPUnit + PHPStan gates plus new Phase 2 tests; resolve all failures.
7. Rollback is the Phase 1 reset path (`bin/serve --fresh`) — Phase 2 ships no production data, and the only added database object is the `schema_change_log` table which can be dropped with the standard reset.

## Open Questions

- Should the entry edit form post to `POST /admin/collections/{slug}/entries/{id}` with a hidden `_method` field for delete, or should delete have its own URL (`POST .../entries/{id}/delete`)? Either is consistent with the Phase 1 routing; pick whichever yields the cleaner route table once the templates are drafted.
- For the `select` field type, should options be edited inline on the collection form (a textarea of one option per line) or be a dedicated per-field "options" sub-form? Inline textarea is simpler and matches the calm-admin direction; revisit if option lists grow large in practice.
- The `relation` field type stores a reference to another collection's entry — but Phase 2 has no admin UI to pick a target entry. Is rendering a placeholder `<select>` named `<key>_target` with a comment acceptable until Phase 5, or should `relation` be deferred entirely until Phase 5? PRD lists it as one of the eight types in Phase 2, so the placeholder UI is the safer read.
