## Context

`Seeder::seed()` (`src/Console/Seeder.php`) currently bundles three unrelated concerns behind one opt-in: creating a temporary `admin@example.com` administrator, creating the `pages` and `posts` collections, and seeding three sample `posts` entries. The installer (`InstallerController::complete`) only calls `seed()` when the admin picks "yes" on the sample-data step, so declining it skips the collections too. All collection inserts are hand-built SQL (`createCollection()`, `createPostsCollection()`), bypassing `CollectionRepository`/`CollectionSchemaValidator`.

We need the `posts`/`pages` collections to exist on every install without also creating the throwaway admin account or sample entries, and we need the same creation logic reachable from a settings-page button for existing sites.

## Goals / Non-Goals

**Goals:**
- `posts` and `pages` collections exist after every successful install, opt-in or not.
- One code path creates default collections; installer and settings button both call it.
- Idempotent: never overwrites an existing collection, even one with a different schema under the same slug.
- Settings button gives clear feedback (created N / already present) without becoming a second seeding system.

**Non-Goals:**
- Not changing what "sample data" seeds (still: temp admin + 3 posts entries), only decoupling it from collection creation.
- Not migrating collection creation to go through `CollectionRepository::save()`/`CollectionSchemaValidator` in this change — see Risks.
- Not adding a way to re-run seeding for collections that already exist with a different schema.

## Decisions

- **Extract `Seeder::seedDefaultCollections(): int`** (returns count created) built on a `seedCollection(string $slug, string $name, array $fields): bool` primitive (returns whether it created one). `seed()` (sample-data path) calls `seedDefaultCollections()` first, then still creates the temp admin and posts entries unconditionally when invoked. `InstallerController::complete()` calls `seedDefaultCollections()` directly and unconditionally, separate from the `sample_data === 'yes'` branch which continues to call `seed()` in full.
  - Alternative considered: a standalone `DefaultContentInstaller` class. Rejected — `Seeder` already owns the schema constants (`POSTS_FIELDS`, collection slugs/names) and the raw-SQL insert helper; splitting them out is more surface area for no isolation benefit, since both paths need the same connection and constants.
- **Settings button reuses `Seeder::seedDefaultCollections()`** via a new `SettingsAdminController::seedDefaultCollections()` action (kept on the existing controller — it's the only other admin surface touching site-wide setup, and it already owns `/admin/settings`). Route: `POST /admin/settings/seed-default-collections`, admin-gated, redirects to `/admin/settings` with `flash=`/`error=` query params, mirroring `BackupAdminController::run()`.
- **Keep raw-SQL collection creation for now.** `seedCollection()` still does a direct `INSERT` (idempotency check via `collectionExists()`), not `CollectionRepository::save()`. Both call sites only ever create brand-new rows for known-good, hardcoded schemas, so there is no `entry_values` reconciliation to do and no validator input to trust. Revisit if the settings button starts accepting user-supplied schemas.

## Risks / Trade-offs

- [Settings button runs against an existing DB where `posts` was deleted then a differently-shaped collection reused the slug] → Out of scope per proposal: skip silently if the slug exists, never overwrite. Flash reports "already present."
- [Raw-SQL insert bypasses `CollectionSchemaValidator`] → Mitigated by only ever inserting the two hardcoded, already-valid schemas (`POSTS_FIELDS`, a `pages` field set). No user input reaches this path.
- [Installer's `complete()` now always creates two collections even for a minimal/embedded install that wants a truly empty schema] → Accepted per issue scope; the settings screen doesn't offer a way to remove them, but they're just normal collections an admin can delete afterward via `/admin/collections`.

## Open Questions

None outstanding — sample-data entry-seeding behavior confirmed unchanged (always seeds the 3 sample `posts` entries on opt-in, per proposal).
