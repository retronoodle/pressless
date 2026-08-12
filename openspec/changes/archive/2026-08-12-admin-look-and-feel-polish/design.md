## Context

`templates/layout/base.twig` is a bare shell (doctype, one stylesheet link, `body`/`title`/`admin_scripts` blocks). Every admin template extends it directly and hand-copies the same `<header class="admin-header">` block (title, `<nav class="admin-nav">`, account/logout form) verbatim, then its own `<main class="admin-main">`. There is no intermediate "admin shell" template. Styling lives entirely in one file, `public/assets/css/admin.css` (353 lines), using CSS custom-property tokens (`--stead-space-*`, `--stead-text-*`, `--stead-ink`, `--stead-muted`, `--stead-rule`, `--stead-accent`, `--stead-error`) with no build step. This change is presentation-only: no controllers, routes, or Twig variables passed into templates change.

## Goals / Non-Goals

**Goals:**
- Consolidate the duplicated header/nav/account markup into one shared Twig partial so nav changes (active-state logic, new links) happen once.
- Style tables and status badges using existing tokens instead of browser defaults.
- Add button variants (primary/secondary/ghost/danger) and a small semantic color set (success/warning) built from the existing token pattern.
- Add a minimal inline-SVG icon set for nav and common actions.
- Add dark mode via `prefers-color-scheme: dark`, redefining existing color tokens rather than introducing a second system.
- Loosen `.admin-main`'s width constraint for table-heavy views.

**Non-Goals:**
- No CSS framework/build toolchain (Tailwind, Sass, bundler) — stay hand-rolled, no-build, matching `admin-shell`'s existing "no frontend framework" requirement.
- No new JS interactivity beyond what `keyboard-shortcuts.js` already does.
- No user-facing theme toggle (dark mode follows OS preference only, same pattern as `prefers-reduced-motion` already in place).
- No change to route handlers, template variables, or data shape returned to templates.

## Decisions

1. **Shared header partial via Twig `{% include %}`, not a new base layout.** Create `templates/admin/_header.twig` containing the header/nav/account markup, included from each page instead of copy-pasted. Considered making `templates/admin.twig` itself the shared "admin base" that other templates extend — rejected because `admin.twig` is the dashboard page template with its own body content; conflating the two would require restructuring blocks across all 18 templates. An include is a smaller, lower-risk change that directly removes the duplication.
2. **Nav active-state via a passed `active_nav` variable**, not URL-sniffing in Twig. Each controller already renders a specific template; pass a string (e.g. `'collections'`) into the include context so the partial can mark the right link `aria-current="page"`. Avoids fragile string-matching on the current path inside the template.
3. **Table/badge/button styles added to the existing single `admin.css`** rather than split into per-component files, consistent with the current no-build, single-stylesheet setup.
4. **Icons as inline SVG `{% include %}` partials** (one file per icon under `templates/admin/_icons/`), not an icon font or external sprite — keeps everything server-rendered with no new asset pipeline.
5. **Dark mode redefines the existing custom properties** under `@media (prefers-color-scheme: dark)` on `:root`, mirroring how `prefers-reduced-motion` is already handled — no separate dark-specific class or JS.

## Risks / Trade-offs

- [Consolidating header markup could subtly change nav behavior on a page that had drifted] → Before extracting, diff all current header blocks across templates to confirm the dashboard's `aria-current` logic (currently hardcoded to "Dashboard") is generalized correctly for every page.
- [Widening `.admin-main` may affect non-table pages (forms, settings) that were tuned for the narrower width] → Scope the width change to table-heavy views only (e.g. a `.admin-main--wide` modifier class) rather than changing the global default.
- [Dark mode could reveal low-contrast combinations not caught in light mode] → Spot-check status badges and buttons in dark mode against WCAG contrast after implementation.

## Migration Plan

Purely additive/refactor CSS and template changes; no data migration. Roll out as a single change; rollback is a plain git revert since no persisted state is affected.

## Open Questions

None — scope is bounded to presentation-layer templates and `admin.css`.
