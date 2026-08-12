## Context

The `media` table exists in the initial schema (`id, filename, mime_type, size_bytes, path, uploaded_by, created_at`) but nothing reads or writes it. `MediaFieldType::renderForm()` emits a disabled input. Uploaded-file serving has no precedent except `AssetController`, which serves *theme* assets from a fixed, trusted `themes/{active}/assets/` directory — it isn't a template for handling untrusted user uploads (no mime/size validation, no derived-file cache).

## Goals / Non-Goals

**Goals:**
- Upload, store, list, and serve media files.
- Generate and cache resized image variants (a fixed set of named sizes, not arbitrary on-the-fly transforms).
- Wire `MediaFieldType` to a real picker sourced from the library.

**Non-Goals:**
- Remote/S3 storage driver (local filesystem only; Phase 12 revisits storage targets for backups, not media itself).
- Cropping/editing UI, folders/tagging, bulk operations, alt-text/SEO metadata (SEO fields land in Phase 13).
- Non-image file transforms (video, documents) — upload/serve only, no transform, for non-image mimes.

## Decisions

- **Storage layout**: `storage/media/{id}/{original-filename}` for originals, `storage/media/{id}/{size}.{ext}` for transforms, where `{id}` is the DB row id. Using the id (not a hash) as the directory key keeps lookups a single indexed query and avoids collision handling. `storage/` sits outside `public/` (or the doc-root equivalent) so nothing is directly web-served without going through `MediaServeController`'s validation — mirrors why `AssetController` doesn't just point Apache/nginx at the themes folder.
- **Validation**: allow-list of mime types (`image/jpeg`, `image/png`, `image/gif`, `image/webp` for v1 — matches what the transform layer supports) and a configurable max size (default e.g. 10MB), checked server-side against both the client-reported mime and the actual file content (`finfo`/`getimagesize`), not the filename extension alone.
- **Transform layer**: GD (`ext-gd`), not Imagick. GD is bundled with PHP on effectively all shared/cPanel hosts by default; Imagick is a less commonly enabled extension. Matches the PRD's "GD or Imagick wrapper" with GD chosen for the lower-friction default matching the target audience (§2 Audience, §4 Tech). A thin `ImageTransformer` interface keeps an Imagick driver swappable later without touching call sites.
- **Transform generation timing**: on-demand, generated on first request for a given `{id}/{size}` and cached to disk (analogous to Phase 4's page cache), rather than eagerly at upload time. Avoids paying transform cost for sizes never actually used by a theme, and keeps upload fast.
- **Named sizes, not arbitrary dimensions**: a small fixed config (e.g. `thumbnail`, `medium`, `full`) rather than accepting `?w=123&h=456` query params. Keeps the transform cache bounded and avoids an image-resizing-as-a-service attack surface (unbounded distinct dimension requests).
- **`media` field storage**: keep the existing placeholder's `{"id": int}` shape in `value_json` — only the picker UI and read-side rendering change, so no data migration is needed for any entries saved during earlier phases' testing.
- **Repository**: a `MediaRepository` over the existing table, following the same thin-PDO-wrapper pattern as `EntryRepository`/`CollectionRepository` — no new architectural pattern introduced.

## Risks / Trade-offs

- [Unbounded upload size/type abuse] → Enforce max size and mime allow-list before the file touches disk; reject early on `Content-Length` when possible.
- [Transform cache grows unbounded across many sizes × images] → Fixed named-size set (see Decisions) bounds worst case to `N images × M sizes`; no cache eviction needed for v1 given that bound.
- [Path traversal via filename] → Never use the client-supplied filename as a path segment; only the DB-generated numeric `id` and a server-controlled size key form the storage path. Original filename is stored as metadata only.
- [GD choice limits future format support (e.g. AVIF)] → `ImageTransformer` interface isolates the GD dependency; a later Imagick or format-specific driver can be swapped in without touching `MediaAdminController` or `MediaFieldType`.

## Migration Plan

No schema migration required (table pre-exists). Deploy is additive: new controllers/routes/templates, new `ext-gd` requirement in `composer.json`. Rollback is a straight revert of this change's commits — no data written by earlier phases depends on the new code paths.

## Open Questions

- Exact storage root path (`storage/media/` vs. a `public/media/`-adjacent path) — finalize during implementation based on how the front controller's doc-root is configured; doesn't affect the interface design above.
