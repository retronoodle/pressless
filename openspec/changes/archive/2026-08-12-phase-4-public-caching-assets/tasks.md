## 1. Collection version tracking

- [x] 1.1 Build a small `CollectionVersionStore` reading/writing `{paths.cache}/public/versions/{collectionId}` (missing file = version 0, lazy-mkdir the directory)
- [x] 1.2 Wire `EntryAdminController::store`/`update`/`destroy` to bump the mutated entry's collection version after a successful write
- [x] 1.3 Unit tests: read with no file returns 0, bump creates/increments the file, independent collections have independent counters

## 2. Page cache

- [x] 2.1 Build a `PageCache` service: `remember(string $key, callable $render): string` — reads `{paths.cache}/public/pages/{sha1(key)}.html` if present, otherwise calls `$render()`, writes the result, and returns it
- [x] 2.2 Wire `PublicController::home` to build a key from all collections' versions and wrap its render call in `PageCache::remember`
- [x] 2.3 Wire `PublicController::collection` to build a key from the collection's version, slug, and page query param, wrapped after the existing 404 check
- [x] 2.4 Wire `PublicController::entry` to build a key from the collection's version and both slugs, wrapped after the existing 404 checks
- [x] 2.5 Unit/integration tests: first request renders and caches, repeat request served from cache (assert render isn't called again — e.g. via a spy renderer or by asserting DB isn't queried), 404s never cached

## 3. Cache invalidation

- [x] 3.1 Integration test: update an entry via admin → confirm its collection version bumped → confirm the entry's page and its collection's listing page re-render on next request
- [x] 3.2 Integration test: create an entry → confirm homepage and collection listing re-render on next request
- [x] 3.3 Integration test: delete an entry → confirm collection listing/homepage re-render and the deleted entry's page still 404s
- [x] 3.4 Integration test: a change to collection A does not invalidate a cached page belonging to collection B

## 4. Asset serving

- [x] 4.1 Add `GET /assets/{path}` to `Routes.php`, registered ahead of the `/{collectionSlug}` pattern
- [x] 4.2 Build an asset controller resolving `{path}` under the active theme's `assets/` directory, rejecting traversal (`realpath` + prefix check) with the existing 404 path
- [x] 4.3 Add a small extension → `Content-Type` map covering common static types (css, js, png, jpg, svg, woff2, etc.) and set `Cache-Control` on the response
- [x] 4.4 Tests: existing asset served with correct content-type, unknown asset 404s, traversal attempt 404s

## 5. Starter theme styling

- [x] 5.1 Create `themes/starter/assets/` with a hand-rolled stylesheet (no framework)
- [x] 5.2 Link the stylesheet from `base.twig` via the `/assets/{path}` route
- [x] 5.3 Write `docs/theming.md` documenting the theme directory layout (`templates/`, `assets/`), the fallback resolution rule from Phase 3, and the `assets`/`admin` reserved-slug caveat

## 6. Smoke test

- [x] 6.1 Edit an entry whose page is already cached → confirm the public page reflects the change on next load
- [x] 6.2 Load a public page → confirm the starter theme's stylesheet loads and applies
