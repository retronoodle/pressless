## 1. Add gap CSS to admin.css

- [x] 1.1 In `public/assets/css/admin.css`, add the rule `form > *:not(:last-child) { margin-bottom: var(--stead-space-3); }` to space direct-child form rows (covers wrapper-`<label>` and `<p>` row patterns).
- [x] 1.2 In the same file, add the rule `form fieldset > *:not(:last-child) { margin-bottom: var(--stead-space-3); }` to space rows inside nested fieldsets (entries SEO section, collections schema).
- [x] 1.3 Place both rules near the existing `label { margin-bottom: ... }` rule (around line 58) so all field-spacing rules live together.

## 2. Verify visual result

- [x] 2.1 Reload `/admin/settings` and confirm the three fields (Site name, Timezone, Date format) now read as comfortably spaced from one another, with the Save settings button flush at the bottom (no trailing gap below it).
- [x] 2.2 Reload at least one of the wrapper-`<label>` forms (e.g. `/admin/mail-settings`, `/admin/users/new`) and confirm the same gap appears between fields there.
- [x] 2.3 Reload at least one of the `<p>` row forms (e.g. `/admin/media`, `/admin/themes`) and confirm the gap matches the wrapper-`<label>` forms.
- [x] 2.4 Reload `/admin/collections/{id}/edit` and confirm the schema field rows inside the fieldset have the same gap, both between top-level rows and between nested schema rows.
- [x] 2.5 Reload `/admin/entries/{id}/edit` and confirm the three SEO fields (Meta title, Meta description, etc.) inside the `<fieldset class="field field-seo">` are spaced by the same gap.

## 3. Spot-check edge cases

- [x] 3.1 Confirm the permissions table (`/admin/permissions`) checkbox rows are unchanged — those `<label>`s live inside `<table>` cells, not direct form children, so the new rule should not match them.
- [x] 3.2 Toggle dark mode and confirm the gap is unchanged in dark mode (only `padding`/`color` rules should differ; the new rule uses a token, not a color).
- [x] 3.3 Spot-check that no other admin page's layout has regressed — in particular, the form-flash and form-errors sections above the form should still sit at their original distance from the page header and from the first field.
