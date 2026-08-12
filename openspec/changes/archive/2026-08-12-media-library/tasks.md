## 1. Storage & repository

- [x] 1.1 Add `ext-gd` to `composer.json` requirements
- [x] 1.2 Build `MediaRepository` over the existing `media` table (create, find by id, list paginated)
- [x] 1.3 Build local filesystem storage helper: resolve `storage/media/{id}/` paths, write original file, read for serving

## 2. Upload endpoint

- [x] 2.1 Build upload endpoint: mime allow-list + server-side content detection, max size check, store file + create `media` row
- [x] 2.2 Add field-scoped/flash error responses for rejected uploads (bad mime, oversize)

## 3. Media library admin UI

- [x] 3.1 Build `MediaAdminController` (index/upload actions) following the `CollectionAdminController` pattern
- [x] 3.2 Build `templates/admin/media/index.twig` (list with thumbnail, filename, size, upload date, upload form)
- [x] 3.3 Register media admin routes in `src/Http/Routes.php`, guarded by `AuthGuard`

## 4. Image transforms

- [x] 4.1 Build `ImageTransformer` interface + GD implementation (resize to named sizes: `thumbnail`, `medium`, `full`)
- [x] 4.2 Build on-demand transform generation with disk cache under `storage/media/{id}/{size}.{ext}`
- [x] 4.3 Reject unknown size keys and non-image mime types at the transform layer

## 5. Media serving

- [x] 5.1 Build `MediaServeController` for `/media/{id}/{file}` with traversal guard and `Content-Type` header, mirroring `AssetController`
- [x] 5.2 Register serving route in `src/Http/Routes.php`

## 6. Media field type integration

- [x] 6.1 Update `MediaFieldType::renderForm()` to render a picker (select existing / upload new) sourced from `MediaRepository`
- [x] 6.2 Add validation: submitted media id must reference an existing `media` row
- [x] 6.3 Update `templates/admin/entries/_field.twig` partial if needed to support the picker's markup/assets

## 7. Verification

- [x] 7.1 Smoke test: upload image via media library → confirm it's listed
- [x] 7.2 Smoke test: insert uploaded image into a `media` field on an entry → save → confirm the reference persists and the entry renders it on the public page
