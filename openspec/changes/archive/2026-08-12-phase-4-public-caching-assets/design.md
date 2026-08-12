## Context

`PublicController` (Phase 3) hits `CollectionRepository`/`EntryRepository` and re-renders Twig on every request to `/`, `/{collectionSlug}`, and `/{collectionSlug}/{entrySlug}`. There's no cache, and `themes/starter/` only has `.twig` files — nothing serves CSS or images from a theme. `TwigRenderer` already uses `paths.cache . '/twig'` for compiled templates, so `paths.cache` is an established, writable location.

There's no `status`/publish flag wired up yet (deferred to Phase 6), so every saved entry is immediately public — cache invalidation only needs to react to `EntryAdminController::store`/`update`/`destroy`, not to a separate publish action.

## Goals / Non-Goals

**Goals:**
- Cache rendered HTML for the three public routes, keyed so a stale page is never served after content changes.
- Invalidate cheaply and correctly on entry create/update/delete — a false miss (extra render) is fine; a false hit (stale content) is not.
- Serve static files from a theme's `assets/` directory with correct `Content-Type` and cacheable headers.
- Ship enough CSS in `themes/starter/assets/` to make the starter theme look intentional, not styled.

**Non-Goals:**
- Cache warming, purging UI, or admin-visible cache stats.
- HTTP-level caching (ETag/If-Modified-Since) — this is a server-side render cache only.
- Asset pipelines (bundling, minification, hashing) — assets are served as-is from the theme directory.
- Draft/publish-aware invalidation — out of scope until Phase 6 wires up `status`.

## Decisions

**Version tracking via a file, not a DB column.** Rather than adding a `version` column to `collections` (a migration touching both `.mysql.sql`/`.sqlite.sql` dialects), each collection's version is a small counter file at `{paths.cache}/public/versions/{collectionId}`. `EntryAdminController` bumps it (read-int, +1, write) after `store`/`update`/`destroy` succeed. `PublicController` reads it (missing file = version `0`) when building a cache key. This keeps the cache entirely file-based per the PRD goal, avoids a schema migration for a value nothing else needs to query, and means a cache wipe (`rm -rf` the cache dir) also resets versions consistently — no orphaned DB state.
  - *Alternative considered:* DB column on `collections`. Rejected — adds migration + repository surface for a counter that's cache-internal.

**Cache key: route + params + collection version, hashed to a filename.** `home` includes every listed collection's version (any collection's entries changing invalidates the homepage); `collection`/`entry` include just that collection's version plus the query string (`page`) or entry slug. Key = `sha1(route . '|' . serialized params . '|' . versions)`, stored as `{paths.cache}/public/pages/{key}.html`.
  - *Alternative considered:* invalidate by deleting all cached files on any mutation. Rejected — defeats the purpose across multi-collection sites; version-scoped keys let unrelated collections' cached pages survive.

**Cache is a decorator around `PublicController`'s existing render calls, not baked into the controller.** A small `PageCache` service wraps "compute key → read-or-render-and-write". `PublicController::home/collection/entry` call `PageCache::remember($key, fn() => $this->renderer->render(...))` instead of calling the renderer directly. Keeps the controller's existing 404 logic (which must never be cached as a hit for a since-created slug) untouched — 404s are returned before the cache is consulted.
  - *Alternative considered:* middleware wrapping the whole response. Rejected — 404 handling and per-route key composition are cleaner living next to the render calls that already know the collection/entry.

**Assets served by a dedicated route + controller, not the cache layer.** `GET /assets/{path}` (mounted after the two collection-slug patterns so a collection literally named `assets` still... — see Risks) resolves `path` under the active theme's `assets/` directory, guards against path traversal (`realpath` + prefix check), and streams the file with `Content-Type` from a small extension map and `Cache-Control: public, max-age=…`. No caching layer needed here — the filesystem read is already cheap and correctness (traversal safety) matters more than speed.

## Risks / Trade-offs

- **Route collision: a collection slugged `assets`.** → `/assets/*` is registered as a fixed-prefix route ahead of the `/{collectionSlug}` pattern, same as admin routes today; a collection named `assets` would be shadowed. Documented as a reserved slug in `docs/theming.md`; acceptable for v1 (same class of constraint as existing reserved paths like `admin`).
- **Version-file races under concurrent writes.** Read-modify-write on the counter file isn't atomic; two simultaneous saves to the same collection could both read the same version and write the same next value, meaning one save's invalidation is "lost" (page stays cached one version behind reality) until the next save. → Acceptable for v1: single-admin-writer is the common case, and worst case self-heals on the next mutation. Noted as an open question below if it needs real locking later.
- **Cache directory grows unbounded.** No eviction. → Matches Twig's existing compiled-cache behavior (also unbounded); revisit only if it becomes an operational problem.
- **Stale cache surviving a manual DB edit** (bypassing `EntryAdminController`). → Out of scope; the smoke test and docs will note that `rm -rf {paths.cache}/public` is the manual escape hatch.

## Migration Plan

No schema migration. Deploy is: ship the code, `paths.cache/public/` is created on first write (same lazy-mkdir pattern `TwigRenderer` already uses). Rollback is reverting the code; nothing persisted outside the cache directory needs cleanup.

## Open Questions

- Is the version-file race (above) worth a `flock()` around the read-modify-write, or is "self-heals next save" good enough for v1? Leaning toward deferring — flag if it causes visible staleness in practice.
