## 1. Seeder refactor

- [x] 1.1 Add `Seeder::seedCollection(string $slug, string $name, array $fields): bool` — idempotent single-collection insert, returns whether it created a row
- [x] 1.2 Add `Seeder::seedDefaultCollections(): int` — calls `seedCollection()` for `pages` and `posts` (using existing `POSTS_FIELDS`/name/slug constants and a new `pages` field-set constant), returns count created
- [x] 1.3 Update `Seeder::seed()` to call `seedDefaultCollections()` for collection creation, then unconditionally create the temp admin and posts entries as before
- [x] 1.4 Remove now-unused `createCollection()`/`createPostsCollection()` in favor of `seedCollection()`

## 2. Installer

- [x] 2.1 In `InstallerController::complete()`, call `Seeder::seedDefaultCollections()` unconditionally (before or independent of the `sample_data === 'yes'` branch)
- [x] 2.2 Keep the `sample_data === 'yes'` branch calling `Seeder::seed()` for the temp admin + sample entries

## 3. Settings action

- [x] 3.1 Add `SettingsAdminController::seedDefaultCollections(Request): Response` — admin-gated, calls `Seeder::seedDefaultCollections()`, redirects to `/admin/settings` with `flash=` reporting count created or "already present" when 0
- [x] 3.2 Add `POST /admin/settings/seed-default-collections` route in `src/Http/Routes.php` near L344, wrapped in `$collectionAuth->requireAdmin(...)`
- [x] 3.3 Add a "Seed default collections" button/section to `templates/admin/settings/index.twig`, mirroring `templates/admin/backups/index.twig`'s "Run a backup now" block

## 4. Tests

- [x] 4.1 Test `Seeder::seedDefaultCollections()` creates both collections on an empty DB and is idempotent on rerun
- [x] 4.2 Test installer completion without `sample_data=yes` results in both `posts` and `pages` existing
- [x] 4.3 Test installer completion with `sample_data=yes` still seeds the three sample `posts` entries
- [x] 4.4 Test `POST /admin/settings/seed-default-collections` creates missing collections and reports count via flash
- [x] 4.5 Test `POST /admin/settings/seed-default-collections` reports "already present" and makes no changes when both collections exist
- [x] 4.6 Test non-admin request to the new route is rejected
- [x] 4.7 Verify existing fixtures relying on the `posts` slug still pass unchanged

## 5. Docs

- [x] 5.1 Note in release notes that `posts` and `pages` are now created on every install
