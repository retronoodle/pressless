## 1. Entry pagination

- [x] 1.1 Add a paginated listing method to `EntryRepository` (limit/offset, page-based) alongside the existing `listByCollection`, returning entries plus whether a next page exists
- [x] 1.2 Unit tests: first page with more entries than page size, page beyond last entry (empty, no error), fewer entries than page size

## 2. Public routes

- [x] 2.1 Add `GET /{collectionSlug}` and `GET /{collectionSlug}/{entrySlug}` to `Routes.php`, registered after all admin routes
- [x] 2.2 Build a public controller handling the collection route: resolve via `CollectionRepository::findBySlug`, fetch paginated entries, 404 via existing Kernel path on unknown slug
- [x] 2.3 Build a public controller handling the entry route: resolve collection then `EntryRepository::findByCollectionAndSlug`, 404 on unknown collection or entry
- [x] 2.4 Integration tests: known/unknown collection, known/unknown entry within a known collection

## 3. Theme-aware template resolution

- [x] 3.1 Add theme path configuration (e.g. `theme.active` / `paths.theme`), unset by default
- [x] 3.2 Update `TwigRenderer` to register the theme's path via `addPath()` before the default `templates/` path when a theme is configured, so theme templates take priority and unmatched names fall back to default
- [x] 3.3 Tests: theme overrides a template, theme falls back for an unprovided template, no-theme-configured behavior is unchanged

## 4. Starter theme

- [x] 4.1 Create `themes/starter/` with `base.twig`, `home.twig`, `collection.twig`, `entry.twig`
- [x] 4.2 Set the starter theme as the default active theme in config
- [x] 4.3 Build `home.twig` to render a basic homepage (can list collections or a static welcome — keep minimal for this phase)

## 5. Smoke test

- [x] 5.1 Create a post in admin → visit `/{collectionSlug}/{entrySlug}` → confirm it renders via the starter theme
- [x] 5.2 Visit `/{collectionSlug}` → confirm the entry appears in the listing
