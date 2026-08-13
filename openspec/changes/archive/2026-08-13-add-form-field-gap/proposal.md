## Why

The recently-shipped bump to form-control vertical padding (from `--stead-space-2` to `--stead-space-3`) made inputs and buttons themselves slightly more comfortable, but on multi-field admin forms the gap between consecutive fields still reads as cramped. The current global rule `label { margin-bottom: var(--stead-space-1); }` (4px) is what governs that gap on most admin forms, so even with taller inputs each label sits very close to the input above it and the form feels visually dense rather than breathable.

This change adds a deliberate gap between form fields so the vertical rhythm carries through the whole form, not just inside each control.

## What Changes

- Add a `margin-bottom` to the form-row element in admin forms so consecutive fields are visually separated by a token-driven gap (default: `--stead-space-3`).
- Apply the gap consistently across all admin form templates, not just the settings page.
- No template markup changes for templates that already use a wrapper-`<label>` as the row container; a CSS-only selector covers them. Templates that use other row containers (`<p>`, fieldsets, table rows) are normalized to the same gap.
- No change to input/button vertical padding — that is owned by the previously-shipped change.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `admin-shell`: add a requirement that consecutive form fields are separated by a token-driven gap so the admin UI's vertical rhythm reads as comfortable across the whole form, not just inside each control.

## Impact

- CSS: `public/assets/css/admin.css` — add a new rule (and possibly override the existing `label { margin-bottom }` for form contexts).
- Templates: minor/no changes if a CSS-only selector covers the existing markup; otherwise add a `.form-row` class to outliers (`templates/admin/collections/form.twig`, `templates/admin/media/index.twig`, `templates/admin/themes/index.twig`, plus the `<fieldset>`-nested labels in `templates/admin/entries/form.twig`).
- No JS, no API, no data, no migrations.
- Scope is admin CSS/templates only — installer CSS and public-facing templates are untouched.
