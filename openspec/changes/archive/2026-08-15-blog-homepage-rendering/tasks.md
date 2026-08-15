## 1. Recency-ordered pagination

- [x] 1.1 Add `EntryRepository::listByCollectionPagedRecent()` — same signature/return shape as `listByCollectionPaged()`, ordered `published_at DESC, id DESC`
- [x] 1.2 Add/update tests: ordering is most-recent-first, pagination fields (`has_next`, `total`, `page`, `page_size`) behave the same as the existing paged method

## 2. Homepage resolution

- [x] 2.1 In `PublicController::resolveHomepage()`, add a `blog` branch: resolve the target collection id as `homepage_collection_id` when set, else (when the type came from the theme default) the `posts` collection by slug, else none
- [x] 2.2 In `PublicController::home()`, when the effective type is `blog`: resolve the `Collection`, call `listByCollectionPagedRecent()` with the `?page=` param (reuse `resolvePage()`), and render a new blog homepage listing; fall back to `collection_list` rendering if the collection can't be resolved
- [x] 2.3 Add/update tests for: blog homepage renders most-recent-first, pagination via `?page=`, falls back to `posts` collection when no override collection is set, falls back to `collection_list` when no collection can be resolved, deleted collection falls back gracefully

## 3. Admin settings UI

- [x] 3.1 Add a `blog` branch to `SettingsAdminController::normaliseHomepageSubmission()` validating `homepage_collection_id` against `CollectionRepository`
- [x] 3.2 Extend `resolveHomepageForDisplay()` to expose a `collections` list (via `CollectionRepository::all()`) for the picker
- [x] 3.3 Add a "Use a blog as the homepage" radio option and collection `<select>` to `templates/admin/settings/index.twig`, updating the effective-type display text to cover `blog`
- [x] 3.4 Add/update tests for: saving a blog override, clearing it, missing/invalid collection id rejected, non-admin rejected

## 4. Templates

- [x] 4.1 Add `home-blog.twig` to `themes/starter/`: entry titles only, linking to each entry, with next/previous pagination links using `?page=`
- [x] 4.2 Add `home-blog.twig` to `themes/meridian/`, matching its existing `collection.twig` styling conventions

## 5. Verification

- [x] 5.1 Run full test suite
- [x] 5.2 Manually verify in dev: set a collection as the blog homepage, confirm most-recent-first ordering and pagination, delete the collection and confirm graceful fallback, clear the override back to theme default