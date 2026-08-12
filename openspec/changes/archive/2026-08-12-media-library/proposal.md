## Why

Entries can declare a `media` field, but it currently renders a disabled placeholder — there is no way to upload a file, browse previously uploaded files, or attach one to an entry. Phase 5 closes that gap so authors can put images into content.

## What Changes

- Add file upload: an admin endpoint that accepts a file, validates mime type and size, and stores it on the local filesystem.
- Add a `media` table-backed repository over the existing (unused) `media` table.
- Add a media library admin screen (list uploaded files, upload new ones, view basic metadata), following the existing `CollectionAdminController`/`EntryAdminController` pattern.
- Add an image transform layer (GD-based) that generates and caches resized/cropped variants of uploaded images.
- Add a `/media/{path}` serving route for uploaded files, mirroring `AssetController`'s traversal-guard + content-type pattern.
- Wire the `media` field type's admin form control to a real picker (select/upload from the library) instead of the disabled placeholder. **BREAKING**: `MediaFieldType`'s stored value shape and rendered markup change from a placeholder to a functioning picker; any code or template relying on the placeholder markup will need updating.
- Add `ext-gd` as a required PHP extension in `composer.json`.

## Capabilities

### New Capabilities
- `media-library`: file upload, local storage, media metadata repository, image transforms, media library admin UI, and public serving of uploaded files.

### Modified Capabilities
- `field-types`: the `media` field type's admin form control and stored value change from a disabled placeholder to a working picker backed by the media library (see "Media and relation fields render placeholders" scenario, which no longer holds for `media`).

## Impact

- New: `src/Media/*` (repository, storage driver, transform service), `src/Http/Controller/MediaAdminController.php`, `src/Http/Controller/MediaServeController.php` (or similar), `templates/admin/media/*.twig`.
- Modified: `src/Content/FieldType/MediaFieldType.php`, `src/Http/Routes.php`, `composer.json` (`ext-gd`).
- No new migration needed — the `media` table already exists in the initial schema; a repository/model is added on top of it.
- Uploaded files land in a new storage directory (e.g. `storage/media/` or `public/media/`, to be decided in design.md) — affects backup scope for Phase 12 later.
