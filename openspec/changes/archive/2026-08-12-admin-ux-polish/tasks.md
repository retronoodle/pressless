## 1. Typography & spacing tokens

- [x] 1.1 Add `--stead-space-{1..6}` and `--stead-text-{sm,base,lg,xl,2xl}` custom properties to `public/assets/css/admin.css`
- [x] 1.2 Replace hardcoded font-size values across `admin.css` with the new text-scale tokens
- [x] 1.3 Replace hardcoded padding/margin/gap values across `admin.css` with the new spacing tokens
- [x] 1.4 Visually smoke-test each existing admin surface (dashboard, collections, entries, media, users, permissions, settings, backups, redirects, mail-settings, invites) after the token pass

## 2. Motion pass

- [x] 2.1 Add transitions for nav state changes, button/form feedback, and panel open/close in `admin.css`
- [x] 2.2 Wrap new transitions in a `prefers-reduced-motion: reduce` guard that disables/reduces them

## 3. Empty / loading / error states

- [x] 3.1 Add `.loading-state` and `.error-state` CSS classes alongside the existing `.empty-state` in `admin.css`
- [x] 3.2 Create `templates/admin/_state.twig` macro for empty/loading/error state markup
- [x] 3.3 Apply the macro to admin list views that currently lack a state pattern (entries, media, users, permissions, redirects, backups history, invites)
- [x] 3.4 Apply the macro to admin form views for error display where currently missing

## 4. Dashboard recent activity

- [x] 4.1 Add `RevisionRepository::listRecent(int $limit)` — cross-entry, most-recent-first, joined for entry title/collection/editor
- [x] 4.2 Add `LoginAttemptRepository::listRecentSuccesses(int $limit)`
- [x] 4.3 Update `AdminController::index()` to fetch recent revisions and recent successful logins and pass them to the view
- [x] 4.4 Update `templates/admin.twig` to render the recent-activity section, using the shared empty-state pattern when there is no activity
- [x] 4.5 Test: dashboard shows recent edits and recent logins after seeding a revision and a login attempt; shows empty state when neither exists

## 5. Keyboard shortcuts

- [x] 5.1 Create `public/assets/js/admin/keyboard-shortcuts.js`: single delegated `keydown` listener, guards against text input/textarea/contenteditable focus
- [x] 5.2 Wire save shortcut to entry/collection edit forms via `data-shortcut` attributes
- [x] 5.3 Wire publish shortcut to entry edit form
- [x] 5.4 Wire navigate shortcut(s) between an entry's list and edit views
- [x] 5.5 Include `keyboard-shortcuts.js` via `<script defer>` only on the templates that declare `data-shortcut` targets

## 6. Admin JS lazy-load convention

- [x] 6.1 Add a Twig block (e.g. `{% block admin_scripts %}`) in `templates/layout/base.twig` for page-specific script includes
- [x] 6.2 Document the one-file-per-concern, include-only-where-used convention (short note in `docs/theming.md` or a new `docs/admin-js.md`)

## 7. Smoke test

- [x] 7.1 Manually compare the admin side-by-side with WP admin using written criteria (chrome elements always visible, DOM node count on equivalent list/edit screens) and record the result
