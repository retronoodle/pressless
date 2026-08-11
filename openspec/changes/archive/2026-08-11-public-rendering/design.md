## Context

Everything routable today lives under `/admin/*` (`src/Http/Routes.php`). `Router::match()` only supports literal segments and single-segment `{name}` placeholders (`Route.php`); there's no catch-all. `TwigRenderer` loads templates from exactly one `FilesystemLoader` path (`paths.templates`, currently `templates/` at repo root) — no theme concept exists. `EntryRepository::findByCollectionAndSlug` and `CollectionRepository::findBySlug` already give us the lookups we need; `listByCollection` returns every row unbounded. The `entries.status` column exists in the schema but nothing in the app reads or writes it yet (Phase 6 territory) — so for this phase, "public" simply means "exists," not "published."

## Goals / Non-Goals

**Goals:**
- `GET /{collectionSlug}` renders a paginated listing of that collection's entries.
- `GET /{collectionSlug}/{entrySlug}` renders a single entry.
- Templates resolve theme-first, falling back to the default templates directory when the theme has no override for a given template name.
- A starter theme ships and is the default active theme out of the box.
- Unknown collection or entry slugs produce the existing Kernel 404 path.

**Non-Goals:**
- Draft/publish filtering on public pages (Phase 6).
- Per-collection public/private opt-in flag (open question, deferred).
- Caching of rendered public pages (Phase 4).
- Theme switching UI in admin (no admin surface for theme selection yet — theme is config-driven).

## Decisions

**Route matching stays single-segment, no catch-all needed.** `/{collectionSlug}` and `/{collectionSlug}/{entrySlug}` both fit the existing `{name}` placeholder mechanism — no router engine change required. Register these two patterns *last*, after all admin routes, so literal paths like `/admin` never fall through to being treated as a collection slug. This matches the existing ordering convention already noted in `Routes.php`.

**Collection-slug collision with reserved paths is accepted, not solved, in this phase.** If someone names a collection `admin`, the admin routes (registered first and literal) win by construction — no reserved-word validation is added yet. This is a known rough edge, not a blocker.

**Theme resolution is a two-loader Twig chain, not a custom resolver class.** Twig's `FilesystemLoader` natively supports multiple paths added in priority order (`addPath()` twice, theme dir first, default `templates/` dir second — Twig returns the first match). This avoids hand-rolling fallback logic and reuses Twig's own resolution semantics. The active theme's directory comes from config (a new `paths.theme` or `theme.active` key); if unset, only the default path is registered and behavior is unchanged from today.

**Starter theme lives under a new `themes/starter/` directory, not inside `templates/`.** Keeps "core default templates" (used when no theme overrides something, and by admin) separate from "theme content." `templates/` keeps its existing admin/login templates untouched.

**Pagination on `listByCollection` uses simple limit/offset with a page number in the query string (`?page=N`).** Matches the paginated listing requirement in the PRD without introducing cursor pagination complexity the MVP doesn't need yet. Page size is a fixed constant for this phase (not user-configurable) — configurability can come later if needed.

**No draft/publish filtering.** Since `status` isn't wired up anywhere in the app (`EntryRepository::save()` hardcodes `'published'`, `hydrate()` doesn't even select `status`), public listing/fetch queries return every entry in the collection, same as admin does today. This is called out explicitly in the proposal so it isn't mistaken for an oversight.

## Risks / Trade-offs

- **All entries are public, including anything a user might expect to be a draft** → Mitigation: explicitly documented as a known gap closed in Phase 6; not a regression since draft state doesn't exist at the app level yet regardless.
- **Collection slug could collide with a reserved top-level path (`/admin`, future `/plugins`, etc.)** → Mitigation: admin routes are registered first and are literal, so they always win; a real reserved-word check is deferred until it's needed (e.g. Phase 17's `/plugins/{slug}` namespace).
- **No caching means every public request re-queries the DB** → Mitigation: explicitly out of scope, Phase 4 owns caching.

## Open Questions

- Should collections eventually get an explicit "publicly routed" boolean, or is "every collection is public" the permanent MVP stance? Flagged in proposal Impact section, not resolved here.
