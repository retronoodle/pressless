## Why

The admin shell (Phase 1) established a bare, functional layout — placeholder navigation, ad hoc CSS with no type scale, one empty-state pattern, and zero JS. Phases 2-13 added real surfaces (collections, entries, media, users, backups, settings) on top of that bare shell without a unifying visual or interaction system, and the PRD's "calm admin" promise (Ghost primary reference, Linear secondary) hasn't been validated end-to-end. Phase 14 closes that gap before the plugin-system phases (15-21) add more admin surfaces on top of whatever foundation exists here.

## What Changes

- Add a dashboard "recent activity" section to the existing `/admin` shell: recent entry edits (from `revisions`) and recent logins (from `login_attempts`), via new repository query methods — no schema changes.
- Formalize a type scale and spacing scale as CSS custom properties in `public/assets/css/admin.css`, and apply them consistently across all existing admin templates (replacing hardcoded ad hoc values).
- Add a small set of CSS transitions (nav state, button/form feedback, panel open/close) with `prefers-reduced-motion` respected.
- Establish and apply consistent empty/loading/error state markup across admin list and form views (extending the one existing `.empty-state` pattern to loading and error cases, and to surfaces that currently lack any of the three).
- Introduce the admin's first JS: a small vanilla-JS keyboard shortcut layer (save, publish, navigate between list/edit) with no build tooling required (plain `<script>`, no bundler dependency) plus a lazy-loading convention for admin JS so it only loads on pages that need it.
- Manual smoke test comparing the admin side-by-side with WordPress admin for "quieter" qualitative feel (documented, not automated).

## Capabilities

### New Capabilities
(none — this phase extends the existing admin shell rather than introducing a new capability domain)

### Modified Capabilities
- `admin-shell`: adds a dashboard recent-activity requirement, a formalized typography/spacing system, motion/transition behavior, consistent empty/loading/error states across admin surfaces, and keyboard shortcuts — extending "Accessible minimal presentation" and "Authenticated admin shell" requirements.

## Impact

- `public/assets/css/admin.css` — reworked with type scale + spacing tokens; existing hardcoded values migrated.
- `templates/admin.twig`, `templates/layout/base.twig`, and all `templates/admin/**/*.twig` — updated to use new spacing/typography classes and consistent empty/loading/error markup.
- `src/Content/RevisionRepository.php` — add a cross-entry "recent revisions" query method.
- `src/Auth/LoginAttemptRepository.php` — add a "recent successful logins" query method.
- `src/Http/Controller/AdminController.php` — dashboard action passes recent-activity data to the view.
- New: `public/assets/js/admin/` — first admin JS (keyboard shortcuts + lazy-load convention), no new composer/npm dependency.
- No database migrations required.
