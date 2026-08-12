## Context

`entries.status`, `entries.author_id`, `entries.published_at`, and the `revisions` table (`id`, `entry_id`, `author_id`, `payload LONGTEXT`, `created_at`) already exist in `20260811000001_initial_schema.{mysql,sqlite}.sql` but are unused: `Entry` doesn't carry a `status`, `EntryRepository::save()` hard-codes `status = 'published'`, and public lookups (`PublicController` → `EntryRepository::findByCollectionAndSlug`/`listByCollectionPaged`) don't filter by status. No schema migration is needed for this change — only application code and one config key.

## Goals / Non-Goals

**Goals:**
- Entries have a real draft/published lifecycle, controllable from the admin.
- Public routes only ever serve published entries.
- Every save snapshots a revision; retention is bounded and configurable.
- An editor can view an entry's revision history and restore an old one.

**Non-Goals:**
- No revision diffing/comparison UI (list + restore only, per PRD Phase 6 scope).
- No scheduled/future publishing (`published_at` in the future) — out of scope for this phase.
- No per-role publish permission gating — that's Phase 7 (roles & permissions); any authenticated admin can publish/unpublish for now, same as existing entry CRUD.
- `author_id` on `revisions`/`entries` is populated from the session user where available but authorization isn't enforced (Phase 7).

## Decisions

**Status values and default.** Two values: `draft`, `published` (matches the existing `DEFAULT 'draft'` column default and PRD §6 "Draft / publish / revisions"). `Entry::save()` stops passing a hard-coded `'published'` on insert and instead defaults new entries to `'draft'` unless the caller requests immediate publish. Reason: matches the schema's own default and the PRD's explicit draft-first flow; "save" and "publish" become distinct actions, mirroring Ghost (the PRD's own admin UX reference).

**Publish/unpublish as repository methods, not just a status value on save.** `EntryRepository::publish(int $id)` / `unpublish(int $id)` set `status` and `published_at` (publish sets it once, if not already set; unpublish leaves `published_at` untouched so republishing doesn't lose the original publish date) and bump the collection's cache version themselves — consistent with how `save()`/`delete()` already couple persistence with `CollectionVersionStore::bump()` in `EntryAdminController`. Alternative considered: fold status into the generic `save()` payload — rejected because publish/unpublish are one-field, no-form-resubmission actions (a single POST button), not full entry edits, and conflating them would force the edit form to always carry a status field through validation.

**Published-only fetch path via a repository parameter, not a second repository.** Add `?string $status = null` (or a small enum-like const) to `findByCollectionAndSlug`, `listByCollection`, `listByCollectionPaged`; `PublicController` passes `status: 'published'`, admin call sites pass nothing (see all statuses). Alternative considered: separate `PublicEntryRepository` — rejected as unnecessary duplication of query logic for one WHERE clause; the existing single-repository-per-entity pattern (`EntryRepository` is already the sole SQL touchpoint per its own docblock) should hold.

**Revision snapshot shape.** `payload` is a JSON-encoded map of the same `field_key => raw submitted value` shape `EntryRepository::writeValues()` already consumes, plus `slug` and `title`, captured as part of `save()`'s existing transaction — snapshot the entry's *current* (pre-save) values by reading `find($entryId)` before the `entry_values` delete, not the incoming payload. This makes "revision" mean "what the entry looked like before this save," matching the PRD's undo framing, and restore-from-revision replays a payload through the exact same `save()` path (so a restore itself produces a new pre-restore revision — free undo-of-undo). No revision is written on first create (nothing to snapshot yet — the create itself is the first state, consistent with "revision = prior state").

**Retention/pruning.** New `content.revision_retention_limit` key in `config/app.yaml` (default 20), read via `Configuration::getInt()`. After inserting a revision, delete the oldest rows for that `entry_id` beyond the limit (`ORDER BY created_at DESC, id DESC LIMIT ... OFFSET limit`, or fetch-ids-then-delete for portability across MySQL/SQLite) inside the same transaction as the save. Alternative considered: a scheduled/cron prune — rejected, adds an operational dependency (Phase 12 backups/scheduler doesn't exist yet) for something that's O(1) extra work per save.

**Restore implementation.** `EntryAdminController::restore($entryId, $revisionId)` loads the revision's decoded payload, re-validates it through the existing `EntryValidator` (a stored revision could predate a since-added required field), and calls `EntryRepository::save()` with it — reusing validation, slug recompute, and revision-snapshot-on-save rather than a bespoke restore path.

## Risks / Trade-offs

- **[Risk]** Adding a status filter to shared fetch methods could silently break an existing caller that expects to see all entries → **Mitigation:** default parameter value preserves current (unfiltered) behavior for every existing call site except the two in `PublicController`, which are updated explicitly in this change.
- **[Risk]** Revision payload stores raw field values, not field-type-bound values — if a field type's serialization format changes later, old revisions could fail to restore cleanly → **Mitigation:** restore runs through `EntryValidator` + `save()`, the same path as a normal form submit, so a stale/invalid revision fails the same way a stale form submission would (validation errors shown, no partial write).
- **[Risk]** Pruning inside the save transaction adds write work to every save → **Mitigation:** bounded by `revision_retention_limit` (default 20), a small fixed-cost delete; acceptable given Phase 6's own smoke-test requirement to verify pruning behavior.

## Open Questions

None blocking; scope matches PRD Phase 6 tasks 1-8 and the roles/permissions gap is explicitly deferred to Phase 7 per the PRD's own phasing.
