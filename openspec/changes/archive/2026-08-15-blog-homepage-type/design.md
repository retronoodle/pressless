## Context

The homepage type mechanism (added in `2026-08-13-admin-homepage-type`) already resolves an effective type — admin override → active theme's `theme.json` default → `collection_list` fallback — and persists it on the single-row `settings` table alongside an optional `homepage_page_id` for the `static_page` override. The render path in `PublicController::home()` already branches on the effective type and falls back to `collection_list` when a configured `static_page` entry no longer exists.

This change extends that data layer only: it adds `blog` as a third recognised value in the manifest reader and on the `Settings` value object, and adds a nullable `homepage_collection_id` column to `settings` to pair with it. The actual rendering branch, admin UI, and new templates are deferred to a follow-up change so this one stays narrowly scoped to the data foundation.

## Goals / Non-Goals

**Goals:**
- Recognise `blog` as a third `homepage_type` value in `ThemeManifestReader`, alongside the existing `collection_list` and `static_page`, with identical soft-failure semantics for unrecognised values.
- Persist an optional `homepage_collection_id` on the single-row `settings` table alongside the existing `homepage_page_id`, and round-trip it through the `Settings` value object and `SettingsRepository`.
- Keep all existing `collection_list`/`static_page` behaviour byte-for-byte unchanged.

**Non-Goals:**
- Rendering `blog` at `GET /` (deferred — `home()` keeps its existing branches).
- Admin UI for selecting `blog` and a collection on `/admin/settings` (deferred).
- Recency-ordered pagination, new repository methods, new `home-blog.twig` templates (deferred).
- Any tag on `Collection` itself; the collection association lives entirely in `homepage_collection_id`.

## Decisions

**Add `homepage_collection_id` as a new nullable column, not reuse `homepage_page_id`.** `homepage_page_id` is entry-scoped and only meaningful for `static_page`. A `blog` override needs a collection reference instead. Storing both lets the same `Settings` row carry either kind of reference unambiguously; only the field matching the active `homepageType` will be read by the renderer in the follow-up change, mirroring how `homepagePageId` is already only consulted when `homepageType === static_page`.

**Widen `normaliseHomepageType()` to accept `blog` instead of adding a second normaliser.** The existing static-method already maps any unrecognised string to `null`, so accepting one more recognised value is a one-line change and keeps the contract intact.

**Mirror the existing `homepagePageId` gating for `homepageCollectionId`.** `homepageCollectionId` should only be retained when the effective `homepageType` is `blog` — otherwise it is forced to `null` on read, exactly like `normaliseHomepagePageId()` does for the entry id. This keeps the in-memory `Settings` row unambiguous.

**No new repository method, controller branch, or template in this change.** Even though the new column is now persisted, no code reads it at render time yet; the renderer still only consults `homepageType`/`homepagePageId`. This is what makes the change safe to merge in isolation.

## Risks / Trade-offs

- [Migration adds a nullable column with no current reader] → Accepted: the column is read by `SettingsRepository` so it round-trips through the value object, but no renderer queries it. A follow-up change wires the rendering branch.
- [Theme declares `blog` but no admin override exists yet] → No visible effect today: the renderer still treats unknown effective types as `collection_list`, matching today's behaviour.

## Migration Plan

1. Add migration adding nullable `homepage_collection_id` (integer) to `settings` — mysql + sqlite, following the `20260813000003_homepage_settings` pattern.
2. Extend `ThemeManifestReader`: add `HOMEPAGE_TYPE_BLOG`, extend `HOMEPAGE_TYPES`.
3. Extend `Settings`/`SettingsRepository` to read/write `homepageCollectionId`, and widen `normaliseHomepageType()` to also accept `blog`.
4. No rollback complexity: dropping the column and reverting the three code changes restores today's behaviour exactly, since this change is purely additive to the data layer.

## Open Questions

- None — this change is intentionally minimal and every edge case (unrecognised manifest value, NULL override, stale column value) inherits its behaviour from the existing `static_page` path.