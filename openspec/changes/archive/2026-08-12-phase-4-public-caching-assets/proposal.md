## Why

Phase 3 renders public pages by hitting the database and Twig on every request, and the starter theme has no way to serve its own CSS/images — `themes/starter` only contains `.twig` files. Phase 4 adds a file-based cache in front of public rendering so repeat requests are cheap, invalidates that cache the moment content changes, and serves theme assets so the starter theme (and future themes) can ship real styling.

## What Changes

- Add a file-based cache layer keyed by URL + collection version, sitting in front of `PublicController`'s three actions (`home`, `collection`, `entry`).
- Bump a collection's version counter whenever an entry in it is created, updated, or deleted (`EntryAdminController::store`/`update`/`destroy`), so every cache key derived from that collection's version misses on the next request.
- Add an asset-serving route that reads static files from the active theme's `assets/` directory and streams them with an appropriate `Content-Type` and cache-control header.
- Add minimal hand-rolled CSS to the starter theme's new `assets/` directory and reference it from `base.twig`.
- Document the theme directory layout (`templates/` vs `assets/`, the fallback resolution rule from Phase 3) in `docs/theming.md`.

## Capabilities

### New Capabilities
- `public-caching`: file-based response cache for public pages, keyed by URL and collection version, invalidated on entry create/update/delete.
- `theme-assets`: static asset serving from a theme's `assets/` directory, with appropriate content-type and cache headers.

### Modified Capabilities
- None. `public-rendering`'s routes and their success/404 behavior are unchanged; caching wraps around them rather than altering their contract.

## Impact

- **Code:** `src/Http/Controller/PublicController.php` (cache read/write around existing render calls), `src/Http/Controller/EntryAdminController.php` (bump collection version on mutation), `src/Content/CollectionRepository.php` (version counter storage/read), new `src/Http/Cache/*` for the file cache and a new `src/Http/Controller/*AssetController.php`, `src/Http/Routes.php` (new asset route), `themes/starter/assets/` (new), `themes/starter/base.twig` (link the stylesheet), new `docs/theming.md`.
- **Config:** cache uses the existing `paths.cache` directory (already used by Twig's compiled-template cache) under a new `public/` subdirectory.
- **Specs:** new `public-caching` and `theme-assets` specs.
- **Open questions carried forward:** no per-collection public/private flag and no draft/publish filtering exist yet (Phase 3, still deferred to Phase 6) — every entry mutation is treated as cache-invalidating regardless of a future "unpublished" state.
