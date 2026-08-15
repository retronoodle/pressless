## Context

The data layer for `blog` is already in place from `blog-homepage-type`: `homepage_collection_id` round-trips through `Settings`/`SettingsRepository`, and `ThemeManifestReader` recognises `blog` as a valid `homepage_type` value. This change wires the user-visible behaviour on top of that foundation.

Today's homepage branch in `PublicController::home()` covers `collection_list` and `static_page` only, and falls back to `collection_list` when a configured `static_page` entry no longer exists. Collection listing pages (`GET /{collectionSlug}`) already have working pagination (`EntryRepository::listByCollectionPaged()`, `?page=` query param, `has_next`/`page`/`total`), but that pagination is ordered `id ASC` — insertion order, not recency — because it was built for the "browse everything" case in `collection.twig`, not a "what's new" feed. There is no existing concept of "the blog collection"; the `posts` slug is only a seeding convention.

## Goals / Non-Goals

**Goals:**
- Render a paginated, most-recent-first listing of the chosen collection's published entry titles at `GET /` when the effective homepage type is `blog`.
- Add a "Use a blog as the homepage" radio option and collection picker to `/admin/settings`, mirroring the existing `static_page` flow.
- Reuse the existing `?page=` pagination convention and page size so behaviour is predictable and consistent with `GET /{collectionSlug}`.
- Preserve current behaviour exactly when `blog` is not configured.

**Non-Goals:**
- Excerpts, thumbnails, dates, or any listing content beyond entry titles (the ask is titles + pagination only).
- A first-class "this collection is a blog" flag on `Collection` itself — the association lives entirely in the homepage-type setting, matching how `static_page` associates one entry without tagging it.
- Changing the ordering of the existing `GET /{collectionSlug}` pagination — that stays `id ASC`, unchanged.
- RSS/Atom feeds (separate concern, not requested here).

## Decisions

**New repository method for recency ordering, not a new parameter on `listByCollectionPaged()`.** Changing that method's `ORDER BY` would silently change `GET /{collectionSlug}` behaviour for every collection page and every existing caller/test. Instead, add `EntryRepository::listByCollectionPagedRecent()` — same signature and return shape as `listByCollectionPaged()`, but `ORDER BY published_at DESC, id DESC`. This keeps the blast radius to the new blog code path only.

**Reuse `EntryRepository::PAGE_SIZE` (10) and the `?page=` query convention.** No new pagination scheme; `PublicController::resolvePage()` is reused as-is for the homepage route.

**Resolution precedence unchanged, `blog` just becomes a third recognised value.** `resolveHomepage()` gains a branch: if the effective type resolves to `blog`, look up `homepage_collection_id` (or the theme-declared collection — see below) and return `type => 'blog'` with the collection id. `home()` gets a matching case that fetches the `Collection`, calls `listByCollectionPagedRecent()`, and renders a new listing template. If the collection no longer exists, fall back to `collection_list` rendering — identical fallback shape to the existing deleted-static-page case.

**Theme-declared `blog` default also needs a collection reference.** Unlike `static_page` (where the admin always picks the entry via `homepage_page_id`, even when the theme merely declares the *type*), a theme declaring `homepage_type: "blog"` in `theme.json` has no way to say *which* collection. Simplest consistent behaviour: when the effective type is `blog` via theme default (no admin override), use `homepage_collection_id` if the admin has set one; otherwise fall back to the `posts` collection by slug (matching the seeder's convention), and if that doesn't exist either, fall back to `collection_list`. This avoids inventing a second manifest field for a collection reference that varies per install.

**New template per theme, not a reuse of `collection.twig`.** `collection.twig` renders a collection's own entries under that collection's URL structure and already carries collection-specific chrome (breadcrumb back to home, `{{ collection.name }}` heading). The blog homepage is a different context (site root) even though the listing logic is nearly identical. Add `home-blog.twig` to both `themes/starter/` and `themes/meridian/`, copying the entry-list + pagination markup from each theme's existing `collection.twig`, adding a "previous page" link alongside "next" (collection.twig's pagination is next-only today — trivial and low-risk to include here since it's a new template, not an edit to shared code).

## Risks / Trade-offs

- [Admin picks `blog`, then deletes the chosen collection] → Mitigated by the same graceful fallback to `collection_list` used for a deleted static page.
- [Theme declares `blog` as default with no `posts` collection and no admin-set `homepage_collection_id`] → Falls back to `collection_list`, never errors.
- [New `listByCollectionPagedRecent()` duplicates most of `listByCollectionPaged()`] → Accepted: the duplication is small (one `ORDER BY` clause) and keeps the existing, well-tested method's behaviour completely unchanged rather than risking a shared-parameter refactor.

## Migration Plan

1. Add `EntryRepository::listByCollectionPagedRecent()`.
2. Update `PublicController::resolveHomepage()`/`home()` with the `blog` branch and fallback-to-`posts`-collection logic.
3. Update `SettingsAdminController` (new `blog` branch validating a collection id) and `templates/admin/settings/index.twig` (radio + collection picker).
4. Add `home-blog.twig` to `themes/starter/` and `themes/meridian/`.
5. No rollback complexity: reverting the controller/admin/template changes restores today's behaviour exactly, since `blog` is purely additive.

## Open Questions

- None — behaviour for every edge case (deleted collection, no `posts` collection, theme default without an admin-set collection) is fully specified above.