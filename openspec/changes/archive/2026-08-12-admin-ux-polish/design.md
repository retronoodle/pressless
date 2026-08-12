## Context

The admin shell is a single hand-rolled CSS file (`public/assets/css/admin.css`, ~150 lines) with a small color palette but no type scale or spacing tokens — sizes and paddings are hardcoded per selector, and the file's own header comment says it's a "minimal baseline" meant to expand. There is currently zero JS anywhere in the admin (no `<script>` tags, no bundler, no `package.json`). The dashboard (`AdminController::index()` / `templates/admin.twig`) shows counts and an empty-state CTA, but no activity feed. `revisions` and `login_attempts` tables already carry the data a "recent activity" feed needs; only new repository query methods are required, not new schema.

Constraints from the PRD (§4): no frontend framework, no admin template kit, hand-rolled HTML/CSS/JS only. This phase must not introduce a JS build step (webpack/vite/esbuild) — that would be a first for the project and is out of scope for what "admin JS bundling + lazy loading" needs to achieve here.

## Goals / Non-Goals

**Goals:**
- Formalize spacing/typography as CSS custom properties so all admin templates share one scale.
- Add a recent-activity dashboard section backed by existing tables.
- Establish one consistent empty/loading/error state pattern reused across every admin list/form view.
- Add keyboard shortcuts (save, publish, navigate) as plain, dependency-free JS.
- Define what "admin JS bundling + lazy loading" means without adopting a bundler.

**Non-Goals:**
- No JS framework (React/Vue/etc.) or build tooling (webpack/vite/esbuild/npm).
- No new database tables or columns.
- No automated visual-regression testing against WP admin — the WP comparison smoke test is manual/qualitative.
- No redesign of navigation structure or information architecture — this phase polishes the existing shell, it doesn't restructure it.

## Decisions

**Type scale & spacing as CSS custom properties, not a preprocessor.** Add `--stead-space-{1..6}` and `--stead-text-{sm,base,lg,xl,2xl}` custom properties to the existing `:root` block in `admin.css` alongside the current color tokens, then do a mechanical pass replacing hardcoded `rem`/`px` values in place. Alternative considered: introduce Sass/PostCSS for nesting/variables — rejected, adds a build step the PRD explicitly avoids ("hand-rolled CSS", "no admin template kit") and the codebase has zero CSS tooling today.

**"JS bundling + lazy loading" = plain `<script defer>` per page, no bundler.** Each admin JS file maps to one concern (`keyboard-shortcuts.js`, etc.) under `public/assets/js/admin/`; templates include only the script(s) relevant to that page via a Twig block, so a collection-list page doesn't load entry-editor JS. This satisfies "lazy loading" (script tags only present where needed) and "bundling" in the loose sense of "organized, minimal files served as-is" rather than a webpack output bundle. Alternative considered: a real bundler — rejected as disproportionate for what is currently a few hundred lines of vanilla JS total; revisit if/when plugin admin extensions (Phase 19) meaningfully grow JS surface area.

**Recent activity via new repository methods, not a generic audit-log table.** `RevisionRepository` gets a `listRecent(int $limit)` (cross-entry, ordered by `created_at desc`, joined to entry title/collection for display); `LoginAttemptRepository` gets `listRecentSuccesses(int $limit)`. Alternative considered: a unified `activity_log` table — rejected as new schema for data that already exists in two tables; revisit only if a third activity source appears later.

**Empty/loading/error states as three CSS classes + a Twig macro, not per-page markup.** Extend the existing `.empty-state` class with sibling `.loading-state` and `.error-state` classes (same dashed-card visual language), and add a small Twig macro (`templates/admin/_state.twig`) so each list/form view calls one include instead of hand-writing markup per page. Alternative considered: leave each template to hand-roll its own — rejected, that's the inconsistency this phase exists to fix.

**Keyboard shortcuts scoped to save/publish/navigate only, dispatched via a single delegated listener.** One `keyboard-shortcuts.js` attaches one `keydown` listener at `document` level, checks for existing focus in text inputs/textareas/contenteditable before acting (so shortcuts don't fight typing), and dispatches to page-declared handlers via `data-shortcut` attributes on relevant buttons/links rather than each page wiring its own listener. Keeps the JS framework-free and centralizes the "don't fire inside a text field" guard in one place instead of repeating it.

## Risks / Trade-offs

- [Mechanical CSS token pass touches every admin template] → do it as one focused, reviewable commit per template group (list views, form views, layout) rather than one giant diff; smoke-test each admin surface visually after.
- [Keyboard shortcuts conflicting with browser/OS shortcuts or screen readers] → keep the shortcut set minimal (save/publish/navigate), require a focus guard against text inputs, and document the list in one place so future additions are deliberate, not accretive.
- [No bundler means no minification/tree-shaking] → acceptable at current JS volume (a few small files); explicitly flagged as a revisit point if plugin admin extensions (Phase 19) add substantially more JS.
- [Manual WP-comparison smoke test is subjective] → write down concrete criteria before running it (e.g., DOM node count on equivalent screens, number of always-visible chrome elements) so "quieter" isn't purely a vibe check.

## Migration Plan

No data migration. Rollout is a normal code change:
1. Land CSS token + typography pass first (lowest risk, most visible).
2. Land empty/loading/error state macro + apply to existing surfaces.
3. Land dashboard recent-activity (repository methods + controller + template).
4. Land keyboard shortcuts + JS lazy-load convention last (net-new JS, easiest to isolate/rollback independently since it's additive `<script>` tags).
Rollback for any step is a straight revert — no schema or data changes to unwind.

## Open Questions

- Exact keyboard shortcut keybindings (e.g. `Cmd/Ctrl+S` for save, `Cmd/Ctrl+Enter` for publish) — pick during implementation, keep OS-conflict-free.
- How many "recent" items to show per dashboard section (proposing 5, adjustable).
