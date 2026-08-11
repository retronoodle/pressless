## Why

Phase 2 lets a non-developer define collections and add entries, but nothing is visible outside the admin — there is no public route, no theme, and no way to fetch content for rendering. Phase 3 closes that gap: visiting the site's public URLs must render real entries through Twig, which is the first end-to-end proof the CMS produces an actual website.

## What Changes

- Extend the router with public URL patterns: `/{collectionSlug}` (paginated entry listing) and `/{collectionSlug}/{entrySlug}` (single entry), registered after existing admin routes so literal admin paths still win.
- Build a template resolver that checks an active theme's template directory first, falling back to the existing default `templates/` directory when the theme doesn't override a given template.
- Add a starter theme (`base.twig`, `home.twig`, `collection.twig`, `entry.twig`) under a new theme directory, using the fallback-capable resolver.
- Add paginated listing to `EntryRepository` (`listByCollection` currently returns everything unbounded) so collection pages can page through entries.
- Wire public route handlers to `CollectionRepository::findBySlug` and `EntryRepository::findByCollectionAndSlug`/paginated `listByCollection`, returning the existing Kernel 404 path for unknown collection/entry.
- Every collection is publicly routable by default in this phase — there is no per-collection public/private flag yet (open question, noted in Impact) and no draft/publish filtering (status field exists in the DB but isn't implemented at the application level until Phase 6, so public pages currently show all saved entries).

## Capabilities

### New Capabilities
- `public-rendering`: public-facing routes for collection listings and single entries, theme-aware template resolution with default-theme fallback, and the starter theme.

### Modified Capabilities
- `entries`: `EntryRepository::listByCollection` gains pagination (limit/offset or page-based), used by public collection listing pages.

## Impact

- **Code:** `src/Http/Routes.php` (new public routes), `src/Http/Router.php`/`Route.php` if pagination or multi-segment matching needs adjustment, `src/View/TwigRenderer.php` (theme-aware loader), `src/Content/EntryRepository.php` (pagination), new `src/Http/Controller/*` public controllers, new `templates/themes/starter/` (or similar) directory.
- **Specs:** new `public-rendering` spec; delta to `entries` spec for pagination.
- **Open questions carried forward:** no per-collection "publicly routed" opt-in exists yet — every collection is exposed publicly in this phase; revisit if a private/internal collection type is needed later. Draft/publish filtering on public pages is deferred to Phase 6 when the `status` field is actually wired up.
