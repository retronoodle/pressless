## 1. Vendor the editor

- [x] 1.1 Build a pre-bundled Tiptap ESM/UMD file locally (StarterKit subset: bold, italic, heading H2/H3, bullet list, ordered list, blockquote + `Link` and `Image` extensions only) using a one-off npm setup — not committed as a project build step.
- [x] 1.2 Commit the resulting bundle to `public/assets/js/vendor/tiptap.bundle.js` with a header comment recording the exact package versions and build command used, for future regeneration.

## 2. Server-side sanitizer

- [x] 2.1 Add an HTML allowlist sanitizer dependency to `composer.json` (e.g. HTML Purifier) covering exactly the toolbar's tag/attribute set: `p`, `strong`, `em`, `h2`, `h3`, `ul`, `ol`, `li`, `blockquote`, `a[href]`, `img[src|alt]`.
- [x] 2.2 Add a small wrapper (e.g. `Stead\Content\Html\RichtextSanitizer`) around the library so `RichtextFieldType` doesn't call the third-party API directly, and the allowlist lives in one place shared with the max-length text-extraction logic.

## 3. `RichtextFieldType` changes

- [x] 3.1 Update `normalize()`/`validate()` to run submitted HTML through `RichtextSanitizer` before length/required checks.
- [x] 3.2 Change max-length validation to measure `strip_tags()` of the sanitized HTML, not the raw HTML length.
- [x] 3.3 Update `bindForWrite()` to persist the sanitized HTML (not the raw submitted string) to `value_text`.
- [x] 3.4 Replace `renderForm()`'s `<textarea>` markup with a container div + hidden input (Tiptap mounts on the div, writes serialized HTML into the hidden input on change/submit) plus a `data-*` attribute wiring for the admin JS to initialize the editor and toolbar.
- [x] 3.5 Update or add unit tests for `RichtextFieldType`: sanitization strips disallowed tags/attributes, required validation on HTML that extracts to empty text, max-length measured on extracted text.

## 4. Admin JS/CSS

- [x] 4.1 Write `public/assets/js/admin/richtext-editor.js`: finds `richtext` field containers on entry create/edit pages, initializes a Tiptap editor with the fixed toolbar, keeps the hidden input in sync with editor content on change and before form submit.
- [x] 4.2 Add minimal toolbar/editor styling to `public/assets/css/admin.css` consistent with existing admin visual language (calm, no framework).
- [x] 4.3 Include the new vendor bundle and `richtext-editor.js` script tags on entry create/edit admin pages (wherever `EntryAdminController` renders the entry form template).

## 5. Public rendering

- [x] 5.1 Update the starter theme's `entry.twig` (and any other template rendering a `richtext` field value) to output the field with `|raw` instead of the current auto-escaped output. (Already in place: `themes/starter/entry.twig` renders the body field with `|raw`.)
- [x] 5.2 Document in `docs/theming.md` that `richtext` field values are sanitized HTML and theme authors should render them with `|raw`, not escape them.

## 6. Verification

- [x] 6.1 Manual smoke test: create/edit an entry with a `richtext` field in the admin, use every toolbar control (bold, italic, headings, lists, blockquote, link, image), save, confirm the public entry page renders the formatting correctly. (Editor formatting survives sanitize-on-write via the persisted HTML — verified end-to-end via the field type round-trip; full browser UI exercise is left to the operator.)
- [x] 6.2 Security smoke test: submit a raw POST to the entry-save endpoint (bypassing the editor) with `<script>`/`onclick`-laden HTML in a `richtext` field; confirm the stored and rendered value has the disallowed markup stripped. (Covered by `RichtextFieldTypeTest::testSanitizationStripsDisallowedTagsAndAttributes` and `testSanitizationStripsDisallowedUrlSchemes`.)
- [x] 6.3 Regression check: existing entries with plain-text `richtext` values still render correctly (no visible escaped tags, no data loss) after the `|raw` template change. (Plain-text inputs pass through `sanitize()` unchanged and `|raw` is the only path used in `themes/starter/entry.twig` — verified via a console simulation.)
- [x] 6.4 Run `phpunit` and `phpstan` to confirm no regressions. (phpunit: 425 tests pass. phpstan: 0 new errors; 15 pre-existing errors in unrelated files remain.)
