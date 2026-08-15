## 1. Database

- [x] 1.1 Add migration `20260813000004_homepage_blog_collection.mysql.sql` and `.sqlite.sql` adding a nullable `homepage_collection_id` (integer) column to `settings`

## 2. Theme manifest

- [x] 2.1 Add `ThemeManifestReader::HOMEPAGE_TYPE_BLOG` and extend `HOMEPAGE_TYPES` to include it
- [x] 2.2 Add/update tests covering: valid `homepage_type: "blog"` recognised, unrecognized value still rejected

## 3. Settings storage

- [x] 3.1 Extend `Settings` value object with a `homepageCollectionId` (nullable int) field; widen `normaliseHomepageType()` to accept `blog`; only keep `homepageCollectionId` when type is `blog` (mirroring `normaliseHomepagePageId()`)
- [x] 3.2 Extend `SettingsRepository` to read/write `homepage_collection_id`, defaulting to `NULL`
- [x] 3.3 Add/update tests for reading defaults and saving/clearing a `blog` override

## 4. Verification

- [x] 4.1 Run full test suite
- [x] 4.2 Confirm no renderer changes — `GET /` still branches on the same effective types as before