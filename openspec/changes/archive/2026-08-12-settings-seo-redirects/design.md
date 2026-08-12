## Context

Phase 13 adds three small, mostly-independent features on top of the existing content engine and admin shell: site settings, entry SEO fields, and slug-change redirects. The codebase already has an established single-row settings pattern (`src/Mail/MailSettings.php` + `MailSettingsRepository`, Phase 8) and an established slug-change detection point (`EntryRepository::save()`, inside its transaction). This design reuses both rather than introducing new patterns.

## Goals / Non-Goals

**Goals:**
- Reuse the existing single-row settings pattern for site-wide settings.
- Keep SEO fields as first-class `entries` columns (consistent with `status`/`published_at`), not EAV `entry_values` rows, so they render on the standard entry edit form without per-collection schema configuration.
- Auto-create redirects transactionally with the slug change that causes them, so a redirect and its triggering slug change can never diverge.
- Keep manual redirect CRUD simple (old path → new path, no wildcard/regex matching) — matches PRD scope ("Build redirects table + admin UI", no pattern-matching requirement).

**Non-Goals:**
- No general-purpose settings framework (no dynamic key/value registry) — mirrors the existing per-concern-table convention (mail, backups, now settings).
- No redirect chains resolution (if A→B→C, this ships only literal lookups; a request for A's old path resolves once to B, not transitively to C). Acceptable for v1 given PRD scope.
- No wildcard/regex redirects, no redirect for collection-listing slug changes (collections don't currently support slug renaming).
- No collection-slug-change redirects — only entry-slug changes, since `EntryRepository::save()` is the only slug-change site the PRD calls out (task 5 references "an entry's slug").

## Decisions

**Settings storage: dedicated `settings` table, single row at id=1, reusing the `MailSettings`/`MailSettingsRepository` shape.**
Alternative considered: a generic key-value `site_settings` table. Rejected — the codebase has no such generic settings abstraction anywhere (mail and backups each got their own typed table), and introducing one now for three fields is over-engineering relative to the existing convention.

**SEO fields: three nullable columns added directly to `entries` (`meta_title TEXT`, `meta_description TEXT`, `og_image_id INTEGER FK → media.id`), not EAV `entry_values` rows.**
Alternative considered: ship SEO as three more field-type entries (text/text/media) that collection authors opt into per collection, reusing the existing field-type system. Rejected per explicit product decision — SEO fields should be uniformly available on every entry without extra collection configuration, matching how `status` and `published_at` are already fixed columns rather than EAV rows. This means `EntryRepository::hydrate()` and `save()` are the only two touch points requiring changes, and the entry edit form gains one static "SEO" section (not schema-driven).

**Redirect creation: inline inside `EntryRepository::save()`'s existing transaction, immediately after the new slug is computed and confirmed different from the old one.**
Alternative considered: a lifecycle/event hook (`entry.after_save`) that a separate redirect listener subscribes to. Rejected — the lifecycle hook system doesn't exist yet (it's Phase 18); adding it now to serve one internal caller would be premature scope. Direct repository-to-repository call (`EntryRepository` takes a `RedirectRepository` dependency) keeps the change transactional and contained to Phase 13.

**Redirect path shape: `/{collectionSlug}/{entrySlug}` (matching the public entry route pattern), stored as full literal paths, exact-match lookup only.**
Rationale: matches the only public route pattern that can 404 on a stale slug (`PublicController::entry()`). Collection-listing routes (`/{collectionSlug}`) are out of scope (see Non-Goals).

**Redirect lookup: added inside `PublicController::entry()` at the existing 404 return sites, not as router-level middleware.**
Alternative considered: a Kernel-level catch-all "if route resolves to 404, check redirects." Rejected — per the survey, the router itself doesn't produce entry-level 404s (the pattern always matches `/{collectionSlug}/{entrySlug}`; the 404 is returned from inside the controller after a failed repository lookup), so router-level middleware has no hook point. Checking inside the controller, right before each existing `return new Response('', Response::HTTP_NOT_FOUND)`, is the minimal, correct change.

**Redirect response: HTTP 301 (permanent).**
Rationale: slug changes are treated as permanent URL moves; 301 lets browsers/search engines cache the new location, matching PRD wording ("confirm old URL redirects to new one").

## Risks / Trade-offs

- **[Risk]** A redirect could point at a path that later becomes a *different* live entry (old slug reused by a new entry) → the redirect would incorrectly steal that entry's URL. **Mitigation**: on lookup, check `PublicController` resolves the live entry route *first* (existing behavior — the redirect check only runs at the current 404 sites, after a live lookup has already failed), so a live entry with that slug always wins over a stale redirect.
- **[Risk]** Multiple slug changes on the same entry produce redirect chains that don't resolve transitively (see Non-Goals) → an old-old URL could still 404. **Mitigation**: acceptable for v1; documented as a known limitation, revisit if it becomes a real complaint.
- **[Trade-off]** SEO fields as fixed columns means every entry carries three nullable columns even for collections that will never use them (e.g. an "Authors" collection). Accepted — negligible storage cost, and avoids the alternative's UX cost (per-collection SEO field configuration for a feature that should just work).

## Migration Plan

1. Ship three new migration pairs (mysql+sqlite): `settings`, `redirects`, `entries` SEO columns (additive `ALTER TABLE`).
2. No backfill needed — `settings` seeds a single default row (empty site name, UTC timezone, a default date format) on first admin visit or via migration `INSERT`; SEO columns default to `NULL`; `redirects` starts empty.
3. Rollback: each migration pair is a straightforward drop-table / drop-column reversal if ever needed (no destructive data migration to undo).

## Open Questions

None blocking — scope matches PRD Phase 13 tasks 1-6 exactly.
