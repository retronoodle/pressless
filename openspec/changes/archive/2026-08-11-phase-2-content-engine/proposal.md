## Why

Phase 1 left the admin empty and the database schema ready, but there is no way to define what content looks like or store any of it. Without a typed content model, a starter "Posts" collection, and admin screens to manage collections and entries, the CMS cannot deliver on its core promise — non-technical users defining their own structured content — and every later phase (public site, media, revisions, permissions) has nothing to operate on. Phase 2 closes that gap with the smallest end-to-end content path: define a collection, add fields, create entries, see them listed.

## What Changes

- Introduce a `FieldType` contract and ship the eight core implementations (text, richtext, number, boolean, date, select, media, relation) with per-type validation, persistence, and form rendering.
- Persist a collection's field definitions as JSON on the `collections` table and add collection CRUD (model, repository, admin list, create, edit) so non-developers can define their own schema in the browser.
- Add entry persistence that writes typed values into `entry_values` per field type, plus admin list and create/edit views whose forms are generated dynamically from the collection's schema.
- Implement server-side validation per field type and present field-scoped errors inline in the entry form.
- Add automatic slug generation and per-collection uniqueness for entries so they can be referenced publicly in Phase 3.
- Add a schema-change migration helper that alters `entry_values` columns when an existing collection's field set changes (column add/drop/rename/type change).
- Extend the dev seeder so `--seed` also creates a starter "Posts" collection with three entries, giving evaluators an immediate content path to look at.
- Extend the Phase 1 admin shell, route table, and navigation so collections and entries are reachable from the sidebar.

## Capabilities

### New Capabilities

- `field-types`: The `FieldType` contract and the eight core implementations (text, richtext, number, boolean, date, select, media, relation), each providing schema fragment, persistence, validation, and form rendering.
- `collections`: Collection schema model, repository, and admin CRUD (list, create, edit) for non-developer-managed content definitions.
- `entries`: Entry persistence layer with typed writes to `entry_values`, slug generation/uniqueness, and admin list + create/edit views that render forms dynamically from a collection's schema.
- `entry-validation`: Server-side, per-field-type validation pipeline that surfaces field-scoped errors in the entry form and rejects invalid writes before persistence.

### Modified Capabilities

- `database-foundations`: Add a schema-change migration helper that alters `entry_values` columns when an existing collection's field set is modified (column add/drop/rename/type change) and records the resulting DDL alongside the normal migration bookkeeping.
- `admin-shell`: The authenticated shell must link to and host the new collections and entries admin surfaces, with navigation updated from placeholders to active entries for the content areas.
- `http-routing`: The route table gains collection and entry admin routes (list, create, edit, delete) plus form posts, with deterministic 404/405 behavior preserved.
- `development-server`: The `--seed` flow must additionally create a starter "Posts" collection with a small set of fields and a few sample entries, in addition to the existing administrator and empty collections.

## Impact

- New application code under `src/Content/` (or analogous) covering field types, collection model/repo, entry model/repo, validation, and admin controllers, plus Twig templates for the new admin screens.
- New SQL migration(s) extending the Phase 1 schema only where strictly necessary (e.g., the `entry_values` shape is already JSON-flexible; the helper operates on existing columns); the helper itself lives in application code rather than a migration file.
- New entries in `config/seed.php` (or equivalent) for the sample Posts collection and entries.
- Composer dependency footprint unchanged — Phase 2 uses only libraries already approved for Phase 1 (no ORM, no admin kit, no JS framework).
- Phase 3 (public site) gains a stable contract: collections, fields, entries, and slugs are queryable through repositories and ready to render under a starter theme.
