## Why

Entries are hard-coded to `status = 'published'` on creation (`EntryRepository::save`, `src/Content/EntryRepository.php:166`) and public rendering fetches by slug with no status filter (`PublicController`, `src/Http/Controller/PublicController.php:99`). There is no draft state, no way to unpublish, and no history — every save is immediately live and irreversible. The `entries.status` column and `revisions` table already exist in the schema (unused since the initial migration) but nothing writes or reads them. Phase 6 wires them up: entries can be saved as drafts, published/unpublished explicitly, and every save snapshots a revision an editor can restore.

## What Changes

- Entry save no longer force-sets `status = 'published'`; new entries default to `draft`, and `Entry` gains a `status()` accessor so admin and public code can read it.
- Admin gains explicit **Publish** / **Unpublish** actions on the entry edit screen, each bumping the collection's cache version (reusing the existing `CollectionVersionStore` invalidation used by save/delete).
- **BREAKING**: public entry lookup (`findByCollectionAndSlug`, used by `PublicController`) now excludes non-published entries — a draft or unpublished entry that was previously reachable at its public URL will 404. Collection listing queries used by public pages are filtered the same way.
- Every entry save (create or edit) writes a `revisions` row snapshotting the pre-save field values, author, and timestamp, using the existing `revisions` table and the transaction already wrapping `EntryRepository::save`.
- A configurable revision retention limit (`app.yaml`, default e.g. 20 per entry) prunes the oldest revisions past the limit after each save, in the same transaction.
- Admin gains a revision list view per entry (timestamp, author) and a restore action that reloads an entry's fields from a chosen revision's snapshot and re-saves it (itself producing a new revision, so restores are undoable).
- Two smoke tests per the PRD: publish → unpublish → revert, and save-past-retention-limit → confirm pruning.

## Capabilities

### New Capabilities
- `revisions`: revision snapshotting on entry save, configurable retention/pruning, revision listing, and restore-from-revision.

### Modified Capabilities
- `entries`: entry persistence gains a `status` (draft/published) and publish/unpublish admin actions; entry CRUD admin surface gains publish/unpublish controls.
- `public-rendering`: entry-by-slug and collection-listing fetches used by public routes are scoped to published entries only.

## Impact

- `src/Content/Entry.php` — add `status` property + accessor + `withStatus()`.
- `src/Content/EntryRepository.php` — `save()` stops hard-coding `status`; add `publish()`/`unpublish()`; add published-only variants of `find`/`listByCollection`/`listByCollectionPaged` (or a status filter param) for public use; write/prune revisions inside the existing `transaction()` closure in `save()`.
- New `RevisionRepository` (or similar) for revision CRUD, following the existing repository pattern (`Connection`-backed, one class per concern).
- `src/Http/Controller/EntryAdminController.php` — add publish/unpublish/revisions/restore actions; pass `entry.status` into the edit form.
- `src/Http/Controller/PublicController.php` — swap to the published-only fetch methods.
- `templates/admin/entries/form.twig` — status indicator + Publish/Unpublish buttons + link to revisions.
- New `templates/admin/entries/revisions.twig` for the revision list + restore action.
- `config/app.yaml` — new `content.revision_retention` (or similar) setting.
- New migration (no schema change needed — `entries.status` and `revisions` already exist — unless retention needs no new column, in which case this change may need zero migrations; confirmed during design).
