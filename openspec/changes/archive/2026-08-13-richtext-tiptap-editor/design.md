## Context

`RichtextFieldType` (`src/Content/FieldType/RichtextFieldType.php`) currently renders a plain `<textarea>`, stores raw text in `entry_values.value_text`, and public templates render it auto-escaped (plain text, no formatting possible). The PRD explicitly rejects a WordPress-style drag-and-drop block builder in favor of typed fields, and the admin is hand-rolled HTML/CSS/JS with no admin template kit or framework. This change adds real formatting capability to `richtext` without violating either constraint: Tiptap is used as a single, narrowly-scoped editing component (not an admin framework), and its toolbar is locked to a fixed set of marks/nodes rather than an open block system.

## Goals / Non-Goals

**Goals:**
- Give `richtext` fields real WYSIWYG formatting: bold, italic, H2/H3, bullet/numbered lists, blockquote, link, image.
- Keep the toolbar fixed and small — no arbitrary HTML, no block/layout system, no plugin-extensible marks in this change.
- Guarantee that no unsanitized HTML from an entry author (or a compromised admin session) can reach a public page (stored XSS).
- Keep the change scoped to the `richtext` field type and its immediate callers; no new admin framework, no touching other field types.

**Non-Goals:**
- Block-based editing (Gutenberg/Editor.js style) — explicitly out of scope per PRD.
- Making the toolbar configurable per-collection/per-field in this change (fixed toolbar for all `richtext` fields).
- Collaborative/real-time editing, comments, or version-diffing inside the editor.
- Migrating existing plain-text `richtext` values — they are valid HTML as-is (plain text has no special HTML characters in the common case) and render unchanged.

## Decisions

**Editor: Tiptap over Trix, Quill, or Editor.js.**
Tiptap (ProseMirror-based) lets us configure an explicit allowlist of nodes/marks (`StarterKit` subset + `Link` + `Image`), so the editor itself cannot produce markup outside our intended set — this mirrors the project's "typed fields, not hook soup" stance better than Trix (fewer node types, less control) or Editor.js (block-based, contradicts the PRD's anti-block-builder stance). Quill was considered but its bundle is heavier and its HTML output is harder to constrain than Tiptap's node/mark schema. Tiptap ships as an npm package, vendored into the admin JS bundle at build time — no CDN, matching "from-scratch core, composer/npm only where it earns it."

**Storage format: sanitized HTML in `entry_values.value_text` (no schema change).**
No new column or table. `value_text` already exists; we only change what's written to it (HTML instead of plain text) and add a sanitization step. Avoids a migration.

**Server-side sanitization: HTML Purifier (or equivalent allowlist sanitizer), not trust-the-client.**
The Tiptap editor constrains what a well-behaved browser sends, but the server must not trust client-submitted HTML — a raw POST to the entry-save endpoint bypasses the editor entirely. `RichtextFieldType::validate()`/normalization SHALL run submitted HTML through a server-side allowlist sanitizer configured to match the toolbar's tag set (`p`, `strong`, `em`, `h2`, `h3`, `ul`, `ol`, `li`, `blockquote`, `a[href]`, `img[src|alt]`) before persistence. This is the same allowlist enforced twice (client toolbar + server sanitizer) — defense in depth, not a single point of trust.

**Max-length measured on extracted text, not HTML markup.**
A `max_length: 500` configured by a collection author means "500 characters of content," not "500 characters including `<strong>` tags." Validation strips tags (via `strip_tags` on the sanitized HTML) before comparing to `max_length`.

**No JS build pipeline exists today — vendor a pre-built Tiptap bundle, don't introduce npm/webpack as a runtime dependency.**
The admin currently ships hand-written vanilla JS files served directly from `public/assets/js/admin/` (e.g. `keyboard-shortcuts.js`), with no `package.json`, bundler, or build step in the repo. Adding a full npm build pipeline to the PHP app's runtime would be a much bigger footprint than this change warrants and would complicate the "download release ZIP, extract on host" install path (end users don't run `npm install`). Instead: build a single pre-bundled Tiptap ESM/UMD file *once*, during development (a documented one-off `npm` command, not a project-wide build step), and commit the resulting static bundle to `public/assets/js/vendor/tiptap.bundle.js`. The admin loads it like any other vendored static asset — consistent with "no CDN" and with the existing hand-rolled JS approach. If a future change needs more JS dependencies, revisit introducing a real build step then.

**Public rendering: switch to `|raw` for `richtext` fields in the starter theme.**
Since the stored value is now sanitized HTML rather than plain text, `entry.twig` (and any other template rendering a `richtext` field) must stop auto-escaping it. This is safe only because sanitization happens at write time — rendering trusts the database value, consistent with a sanitize-on-write model (simpler than sanitize-on-read, which would run the sanitizer on every page view).

## Risks / Trade-offs

- **[Risk] Sanitize-on-write means a future change to the allowlist doesn't retroactively re-sanitize existing stored entries.** → Mitigation: allowlist is deliberately fixed and narrow for this change; if it's ever loosened, that's a follow-up change that can re-sanitize existing rows via a one-off script, not routine risk.
- **[Risk] Client (Tiptap) and server (sanitizer) allowlists drifting out of sync over time (e.g. toolbar gains a feature, sanitizer config isn't updated) → stored XSS gap.** → Mitigation: the server sanitizer is the enforced boundary regardless of what the client sends; document the paired allowlist in code comments on both sides so future toolbar changes have a visible reminder to update the sanitizer.
- **[Risk] New composer dependency (HTML Purifier or similar) adds install/vendor weight.** → Mitigation: it's a small, well-established, single-purpose library consistent with "composer only where it earns it" — sanitizing user HTML is exactly the kind of thing not worth writing from scratch.
- **[Trade-off] Fixed toolbar means no per-collection customization in v1** (e.g. a "captions" collection can't get a smaller toolbar). Acceptable for this change; a follow-up could make the toolbar configurable per field if a real need shows up.

## Migration Plan

- No database migration required — `value_text` column is unchanged.
- Deploy order: ship the field type + sanitizer + admin JS + template change together (all in this change); there's no safe intermediate state where only some of these land, since a `|raw` template change without sanitization would be an XSS regression, and sanitization without the `|raw` change would just make output display broken (escaped tags visible).
- Rollback: revert the change; existing sanitized-HTML entries continue to render fine when rolled back to escaped plain-text rendering (they'll just show visible tags, which is a display bug, not a security or data-loss issue) until forward-fixed again.

- **[Risk] Pre-built vendor bundle can go stale (security fixes in Tiptap/ProseMirror upstream don't auto-apply).** → Mitigation: document the exact npm command and Tiptap version used to produce the bundle in a comment header of the committed file, so it can be regenerated deliberately when needed.

## Open Questions

- Exact sanitizer library choice (HTML Purifier vs. a lighter-weight alternative) — left to tasks.md/implementation given no strong constraint found in the codebase's current composer.json.
