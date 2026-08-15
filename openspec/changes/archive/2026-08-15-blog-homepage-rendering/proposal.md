## Why

The data foundation for a `blog` homepage type is already in place: the `settings.homepage_collection_id` column round-trips through `Settings`/`SettingsRepository`, and `ThemeManifestReader` already recognises `blog` as a valid manifest value. But nothing renders it yet — `PublicController::home()` still only branches on `collection_list` and `static_page`, and the `/admin/settings` Homepage section has no UI for picking a blog override.

This change wires the actual user-visible behaviour:

- `GET /` renders a paginated, most-recent-first listing of the chosen collection's published entry titles when the effective homepage type is `blog`.
- The admin settings page exposes a "Use a blog as the homepage" radio option plus a collection picker that appears when `blog` is selected, mirroring the existing "Use a static page as the homepage" flow.

## What Changes

- New repository method `EntryRepository::listByCollectionPagedRecent()` — same signature/return shape as the existing `listByCollectionPaged()`, but ordered `published_at DESC, id DESC`. The existing `id ASC` ordering used by `GET /{collectionSlug}` is untouched.
- `PublicController::resolveHomepage()` gains a `blog` branch: resolve the target collection id as `homepage_collection_id` when set, else (when the type came from the theme default) the `posts` collection by slug, else none.
- `PublicController::home()` gains a matching case that calls the new repository method and renders a new `home-blog.twig` listing; if no collection can be resolved or the collection no longer exists, it falls back to `collection_list` rendering, mirroring the existing deleted-static-page fallback.
- `SettingsAdminController` accepts `homepage_type = 'blog'` and validates `homepage_collection_id` against `CollectionRepository`; the `/admin/settings` Homepage section gets a blog radio option and a collection picker.
- New `home-blog.twig` template added to both `themes/starter/` and `themes/meridian/` showing entry titles with previous/next pagination links using `?page=`.

## Capabilities

### New Capabilities
(none — this extends existing capabilities)

### Modified Capabilities
- `settings`: adds a "Use a blog as the homepage" radio option and a collection picker to the `/admin/settings` Homepage section, validating the chosen collection against `CollectionRepository`.
- `public-rendering`: `GET /` gains a third branch — when the effective homepage type is `blog`, render a paginated, recency-ordered listing of the chosen collection's published entry titles instead of the collection list or a static page.

## Impact

- `src/Content/EntryRepository.php` (new `listByCollectionPagedRecent()`; existing `listByCollectionPaged()` unchanged).
- `src/Http/Controller/PublicController.php` (`resolveHomepage()`/`home()` — new `blog` branch, paginated recency-ordered rendering).
- `src/Http/Controller/SettingsAdminController.php` (new `blog` branch in `normaliseHomepageSubmission()`, validating `homepage_collection_id`).
- `templates/admin/settings/index.twig` (new radio option + collection picker).
- New `home-blog.twig` template in both `themes/starter/` and `themes/meridian/`.
- Tests: extend `EntryRepository`, `PublicController`, and `SettingsAdminController` tests; new manual-verification checklist for the dev environment.
- No breaking changes: existing `collection_list`/`static_page` behaviour is unchanged; `blog` is purely additive.