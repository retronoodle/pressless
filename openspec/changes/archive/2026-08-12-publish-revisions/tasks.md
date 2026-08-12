## 1. Entry status plumbing

- [x] 1.1 Add `status` (and `withStatus()`) to `src/Content/Entry.php`; hydrate `status`, `published_at` in `EntryRepository::hydrate()` / `find`, `findByCollectionAndSlug`, `listByCollection`, `listByCollectionPaged`
- [x] 1.2 `EntryRepository::save()`: stop hard-coding `status = 'published'` on insert — new entries default to `draft`; status is never touched on update
- [x] 1.3 Add optional status filter parameter to `findByCollectionAndSlug`, `listByCollection`, `listByCollectionPaged` (default: no filter, preserving current admin behavior)
- [x] 1.4 Update `src/Console/Seeder.php` sample entries to go through the same draft-default / explicit-publish path so seeded content is still publicly visible

## 2. Publish / unpublish

- [x] 2.1 `EntryRepository::publish(int $id)` / `unpublish(int $id)`: set `status`, set `published_at` on first publish only, leave it untouched on unpublish
- [x] 2.2 `EntryAdminController`: add `publish`/`unpublish` actions wired to `POST /admin/collections/{slug}/entries/{id}/publish` and `.../unpublish`, bumping `CollectionVersionStore` same as save/delete
- [x] 2.3 Register the two new routes
- [x] 2.4 `templates/admin/entries/form.twig`: show current status, add Publish/Unpublish button
- [x] 2.5 `templates/admin/entries/index.twig`: show status per row

## 3. Public status filtering

- [x] 3.1 `PublicController`: entry route uses `findByCollectionAndSlug(..., status: 'published')`, 404s when not found (already the existing not-found behavior)
- [x] 3.2 `PublicController`: collection listing route uses `listByCollectionPaged(..., status: 'published')`

## 4. Revisions

- [x] 4.1 Build `RevisionRepository` (or equivalent): `save(int $entryId, array $payload, ?int $authorId)`, `listByEntry(int $entryId)`, `find(int $revisionId)`, `pruneOldest(int $entryId, int $keep)`
- [x] 4.2 Add `content.revision_retention_limit` to `config/app.yaml` (default 20) and read via `Configuration::getInt()`
- [x] 4.3 Wire revision snapshot + prune into `EntryRepository::save()`'s existing transaction: snapshot the entry's pre-save state (skip on first create), write it, then prune past the retention limit
- [x] 4.4 `EntryAdminController`: add a `revisions` action (`GET /admin/collections/{slug}/entries/{id}/revisions`) listing revisions newest-first with timestamp + author
- [x] 4.5 `EntryAdminController`: add a `restore` action (`POST .../revisions/{revisionId}/restore`) that loads the revision payload, re-validates via `EntryValidator`, and calls `EntryRepository::save()`
- [x] 4.6 New `templates/admin/entries/revisions.twig` listing revisions with a restore action per row; link to it from the entry edit form

## 5. Verification

- [x] 5.1 Smoke test: publish → unpublish → revert (confirm public visibility toggles correctly and status/`published_at` behave per design)
- [x] 5.2 Smoke test: save an entry past the retention limit → confirm oldest revisions are pruned, not accumulating unbounded
- [x] 5.3 Confirm existing entry/public-rendering tests still pass with the new default-draft behavior (update fixtures/tests that assumed entries were published on create)