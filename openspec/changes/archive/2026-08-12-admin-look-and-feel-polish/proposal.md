## Why

A prior phase (archived at `openspec/changes/archive/2026-08-12-admin-ux-polish/`) introduced type/spacing tokens, empty/loading/error states, and keyboard shortcuts, but several admin surfaces still feel unfinished: every template hand-copies header/nav markup instead of sharing one partial, data tables have zero CSS (browser defaults), buttons have only one visual style, and there's no dark mode despite a token system that already supports it. The admin side reads as inconsistent and unpolished even though the underlying token foundation is solid.

## What Changes

- Extract the duplicated header/nav/account markup (currently hand-copied into every admin template) into a single shared partial included from one real admin base layout.
- Add CSS for data tables (`.entries-table` and similar list views) and status badges so they use the existing token system instead of browser defaults.
- Add button variants (secondary/ghost, danger) so destructive actions (delete user, restore backup) are visually distinct from primary actions, which currently all share one solid-dark style.
- Add a small set of inline SVG icons for nav items and common actions to reduce the plain-text, unpolished feel.
- Add dark mode support (`prefers-color-scheme: dark`) using the existing CSS custom-property token system.
- Widen or adjust `.admin-main`'s `max-width: 48rem` constraint for table-heavy screens (entries, users, permissions) so tables aren't cramped.
- Add a small semantic color set (success/warning, alongside existing error) for status badges and future use.

## Capabilities

### New Capabilities
(none — this extends the existing admin shell presentation layer)

### Modified Capabilities
- `admin-shell`: adds requirements for a shared header/nav partial (replacing per-template duplication), table/badge styling, button variants, icons, dark mode, and content-width handling for table-heavy views.

## Impact

- Affected files: `templates/layout/base.twig`, `templates/admin.twig`, all `templates/admin/**/*.twig` (header/nav markup consolidation), `public/assets/css/admin.css` (tables, badges, buttons, icons, dark mode, color tokens).
- No JS behavior changes; no database or route changes.
- Visual-only change — should not affect existing template variables, controllers, or tests beyond markup structure in shared partials.
