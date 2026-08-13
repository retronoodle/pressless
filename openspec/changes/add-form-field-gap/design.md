## Context

After shipping the form-control vertical-padding bump (`--stead-space-2` → `--stead-space-3`), inputs and buttons themselves read as more comfortable, but the gap *between* consecutive fields on multi-field admin forms is still cramped. The current global rule `label { margin-bottom: var(--stead-space-1); }` (4px) is the only thing governing that gap on templates that use the wrapper-`<label>` pattern, and templates that use `<p>` rows rely on the global `p { margin-bottom: var(--stead-space-4); }` (16px) which is incidentally larger but inconsistent with the wrapper-`<label>` forms.

The exploration of `templates/admin/` shows three field-row patterns in use:

1. **Wrapper-`<label>`** (8 templates: settings, mail-settings, redirects, invites, users/new, users/role, backups, entries SEO fieldset) — the `<label>` IS the row container.
2. **`<p>` row** with label and input as siblings (collections top fields, media, themes) — the `<p>` is the row container.
3. **`<fieldset>` row** with nested fields (collections schema, entries form) — the fieldset or its inner `<p>`/`<label>` is the row container.

Currently the only rule that touches any of these is `label { margin-bottom: var(--stead-space-1) }` (`public/assets/css/admin.css:58-62`). No `form`-, `fieldset`-, or per-form-class rules exist.

## Goals / Non-Goals

**Goals:**
- Add a deliberate, token-driven gap between consecutive form fields across all admin forms so the form reads as comfortably spaced, not just each individual control.
- Apply the gap uniformly regardless of which markup pattern each template uses.
- Make no template markup changes if avoidable (CSS-only fix preferred).

**Non-Goals:**
- Changing input/button vertical padding (owned by the previously-shipped `increase-form-button-padding` change).
- Changing the gap between non-form sections (page header → form, form → footer, etc.).
- Touching installer CSS, public-facing templates, or any non-admin surface.

## Decisions

### Decision 1: CSS-only fix via universal form-row selector

Add the following to `public/assets/css/admin.css` (no template edits):

```css
form > *:not(:last-child) {
    margin-bottom: var(--stead-space-3);
}

form fieldset > *:not(:last-child) {
    margin-bottom: var(--stead-space-3);
}
```

**Rationale.** Every admin form's field row is either a direct child of `<form>` or a direct child of a `<fieldset>` nested inside a `<form>`. `form > *:not(:last-child)` covers the wrapper-`<label>` pattern (settings, mail-settings, redirects, invites, users, backups) and the `<p>` row pattern (collections top fields, media, themes) — `:not(:last-child)` lets the submit button stay flush at the bottom without a trailing gap. The nested rule `form fieldset > *:not(:last-child)` covers labels/paragraphs inside fieldsets (entries SEO, collections schema) so the gap applies at every nesting level that contains form rows.

Specificity (`form > *:not(:last-child)` ≈ 0,2,1) is higher than the existing `label` and `p` rules (0,0,1), so this cleanly overrides their `margin-bottom` for form-row elements without needing additional reset rules.

### Alternatives considered

- **`.form-row` class.** Would be explicit and self-documenting, but requires touching ~10 templates to add the class to each row. CSS-only achieves the same visual result with zero template edits.
- **`form > * + * { margin-top }`.** Margin-top approach avoids the `:not(:last-child)` special case but stacks on top of the global `label`/`p` bottom margins, producing inconsistent gaps (4 + 12 = 16px between labels, 16 + 12 = 28px between paragraphs). Margin-bottom is cleaner here.
- **Bump the global `label` margin-bottom.** Would affect labels outside form contexts (e.g. table cells in `permissions/index.twig` containing checkbox labels), where 12px between stacked checkboxes would visually overspace rows. Scope to forms only.

## Risks / Trade-offs

- **[Risk]** The submit row in some templates is a `<p>` containing only a button, not a true form field. With `form > *:not(:last-child) { margin-bottom }`, if the submit `<p>` is the last child it correctly gets no margin-bottom. If a template places an element *after* the submit (e.g. an info note), that element would be the last child and the submit would now have a 12px margin-bottom pushing it up from the note — which is the desired behavior. No mitigation needed; verified against all 8 wrapper-label templates.
- **[Risk]** `collections/form.twig` mixes `<p>` rows (top fields) and `<p>` rows inside a `<fieldset>` (schema). Both get the gap; the fieldset-to-fieldset gap is governed by `form > fieldset:not(:last-child)`. Visual result matches the wrapper-`<label>` forms.
- **[Risk]** `permissions/index.twig` wraps checkboxes in `<label>` inside `<table>` cells, but those `<label>`s are not direct children of `<form>` — they're inside table cells. The new rule does not match, so checkbox row spacing is unchanged. ✓
- **[Risk]** Any future template that adds an unexpected element between form fields (e.g. an inline hint paragraph) would inherit the gap. This is desirable, not a bug, but worth noting.

## Migration Plan

- No data, no migrations, no JS changes.
- Single-file CSS edit. Reload any admin page to see the new spacing.
- Rollback: remove the two new rules from `public/assets/css/admin.css`. All other admin styling is untouched.

## Open Questions

None. The CSS-only approach is sufficient given the existing template patterns; no design-level ambiguity remains.
