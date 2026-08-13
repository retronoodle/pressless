## Context

`ThemeInstaller::readManifest` already parses `theme.json` for `name`/`version`/`author` and tolerates a missing or malformed file by falling back to slug-derived defaults. `TwigRenderer` re-syncs the active theme's template path on every render via `ActiveThemeResolver`. Settings-style single-row config (`SettingsRepository`, `MailSettingsRepository`) is the closest existing pattern, but theme settings are per-theme and key-value shaped (arbitrary keys from the manifest), not a fixed set of columns — closer to a lightweight EAV table than a single row.

## Goals / Non-Goals

**Goals:**
- Let a theme declare a settings schema in `theme.json` and have admins configure values through a generated form.
- Namespace stored values per theme (by theme id) so switching themes never shows or applies another theme's values.
- Preserve values across re-upload/reactivation for keys that still exist.
- Expose resolved values (stored value, falling back to manifest default) to Twig as `theme_settings`.

**Non-Goals:**
- No file-upload handling for `image`-type settings in this change beyond storing a string (path/URL) — reusing the existing media library upload flow to populate that string is a follow-up, not blocking this change.
- No versioned settings history/audit log.
- No cross-theme settings sharing or global (non-theme) custom settings.

## Decisions

**Key-value table over per-theme dynamic columns.** A `theme_settings(theme_id, key, value)` table with a composite unique key on `(theme_id, key)` avoids schema migrations per theme and matches the manifest's arbitrary, theme-author-defined key set. Alternative considered: JSON blob column on `themes` table — rejected because it makes per-key upsert/read less explicit and complicates the "keep dormant keys" persistence requirement (a blob can't cheaply distinguish "removed from schema" from "explicitly cleared").

**Repository resolves defaults, not the DB layer.** `ThemeSettingsRepository::valuesFor(int $themeId, array $schema): array` reads stored rows and merges manifest defaults for missing keys, returning a flat `array<string, string>`. This keeps "what does the admin see" and "what does Twig see" using the same resolution logic, avoiding drift between the admin form's pre-fill and the rendered site.

**Manifest `settings` schema is read at render/request time, not cached in the DB.** The schema lives in `theme.json` on disk (already read by `ThemeInstaller`); the admin controller and `TwigRenderer` both re-read the active theme's `theme.json` per request (small, local file, already how `ActiveThemeResolver` treats theme discovery). This keeps the manifest as the single source of truth for key/type/label/options — the DB only stores values, never schema — so a re-uploaded theme with a changed schema is reflected immediately without a migration or cache bust.

**Dormant keys, not deletion, on schema shrink.** When a re-uploaded theme's manifest drops a previously-declared key, existing stored rows for that key are left in place (not surfaced in the form, not read into `theme_settings` in Twig since the merge is schema-driven). This matches the issue's stated preference and costs nothing (no unbounded growth risk — theme settings tables are small, admin-authored).

**Escaping**: the `theme_settings` Twig global returns plain strings; Twig's `autoescape: 'html'` (already configured in `TwigRenderer`) escapes them by default in `{{ }}` output. Theme authors who intentionally want a `textarea`-type value rendered as HTML must use `|raw` explicitly — documented as a footgun in the theme-building docs rather than solved with a rich-text sanitizer, consistent with this codebase's minimal-dependency approach.

**Admin route**: `/admin/theme-settings`, a new controller `ThemeSettingsAdminController` (mirrors `MailSettingsAdminController`'s structure: `index` renders the form, `save` validates against the schema's `type` and persists). If no theme is active or the active theme declares no settings, the page shows an explanatory empty state rather than erroring.

**Keyed by theme slug, not theme id.** `ThemeInstaller::install` rejects re-upload while a slug is in use (`assertSlugAvailable`), so today's only "re-upload" path is delete then re-add, which mints a new `themes.id` via `ThemeRepository::create`. Slugs are derived from the ZIP's top-level folder name and stay stable across that delete/re-add cycle, while ids do not. For stored values to survive re-upload/reactivation as required, `theme_settings.theme_slug` (not `theme_id`) is the namespacing column — no foreign key to `themes.id`, since the row a value belongs to may not exist (theme currently deleted) or may point to a different id than a future re-add.

## Risks / Trade-offs

- **[Risk]** Re-reading `theme.json` per request adds filesystem I/O to every themed page render (for the Twig global). → **Mitigation**: file is small and local; `ActiveThemeResolver`/`TwigRenderer` already do per-request theme directory resolution, so this is consistent with existing cost, not new class of cost. Revisit with an opcache-friendly parsed-manifest cache only if profiling shows it matters.
- **[Risk]** `select`-type settings with a stored value no longer in `options` (schema changed) could render an invalid selection. → **Mitigation**: form rendering falls back to the manifest `default` when the stored value isn't in the current `options` list.
- **[Risk]** No sanitization for HTML-capable field values. → **Mitigation**: default autoescape protects output unless a theme author opts into `|raw`; documented explicitly.

## Migration Plan

- Add `database/migrations/20260813000002_theme_settings.mysql.sql` and `.sqlite.sql` creating `theme_settings` with columns `id, theme_slug, setting_key, value, created_at, updated_at` and a unique index on `(theme_slug, setting_key)`. No foreign key to `themes.slug`, by design (see slug-keying decision above): a value row can legitimately outlive its theme's current `themes` row across a delete/re-add cycle.
- No data backfill needed (new table, empty by default).
- Rollback: drop the table; no other schema depends on it.

## Open Questions

- Whether `image`-type setting values should validate against the media library (e.g. require an existing media path) or accept any string. Deferred to task breakdown — likely accept any string for this change, matching the Non-Goals scope, with stricter validation left for when upload UI is added.
