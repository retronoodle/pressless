## Why

The `richtext` field type is currently a plain `<textarea>` that stores and displays escaped plain text — no formatting is possible despite the name and despite the PRD calling for a "calm, modern admin." Authors writing a blog post or page body need basic formatting (bold, headings, links, lists, images) without adopting a WordPress-style block builder, which the PRD explicitly rules out in favor of typed fields.

## What Changes

- Replace the `richtext` field type's admin form control with a Tiptap-based WYSIWYG editor (self-hosted, no CDN), offering a fixed, locked-down toolbar: bold, italic, headings (H2/H3), bullet/numbered lists, blockquote, link, image.
- **BREAKING**: `richtext` fields now store sanitized HTML instead of plain escaped text. Existing plain-text values remain valid HTML (they render as-is) so no data migration is required, but downstream code/themes that assumed plain text must treat the value as HTML going forward.
- Add server-side HTML sanitization of submitted `richtext` values (allowlist of the tags/attributes the toolbar can produce) before persisting to `entry_values.value_text`, closing the stored-XSS risk of accepting editor HTML.
- Update public rendering (Twig entry templates) to output `richtext` values unescaped (`|raw`) since the value is now sanitized HTML, instead of the current auto-escaped plain text.
- Admin JS bundle gains a Tiptap dependency (vendored, no external CDN), consistent with "hand-rolled admin, no template kit" — Tiptap is used only as a focused editing component, not an admin framework.

## Capabilities

### New Capabilities
(none — this extends the existing `richtext` field type rather than introducing a new capability)

### Modified Capabilities
- `field-types`: `richtext` field's admin form control becomes a WYSIWYG (Tiptap) editor instead of a plain textarea; validation/storage now operates on sanitized HTML rather than plain text.
- `entry-validation`: `richtext` max-length and required checks must account for HTML markup (e.g., length counted on stored HTML or on extracted text — decided in design.md) and add HTML sanitization as a validation/normalization step.

Note: public template output (rendering `richtext` as raw sanitized HTML) is a starter-theme implementation detail, not a spec-level requirement change in `public-rendering` — covered in design.md and tasks.md instead.

## Impact

- `src/Content/FieldType/RichtextFieldType.php` — form rendering, validation, and read/write binding.
- Admin JS/CSS build — new Tiptap dependency, editor init script, toolbar styling.
- New server-side HTML sanitizer (e.g. HTML Purifier or equivalent) — new composer dependency.
- Public theme templates (`entry.twig` and any others rendering `richtext` fields) — switch from escaped to `|raw` output.
- Existing entries with `richtext` values: no migration needed (plain text is valid HTML), but should be spot-checked after deploy.
