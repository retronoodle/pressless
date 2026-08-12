## 1. Shared header partial

- [x] 1.1 Diff header/nav/account markup across all `templates/admin/**/*.twig` and `templates/admin.twig` to confirm current variation (active-link logic, role-based items)
- [x] 1.2 Create `templates/admin/_header.twig` with the consolidated header/nav/account markup, accepting an `active_nav` variable to set `aria-current="page"`
- [x] 1.3 Replace hand-copied header blocks in all 18 admin templates with `{% include 'admin/_header.twig' with {active_nav: '...'} %}`, passing the correct section per template
- [x] 1.4 Verify each admin page still renders its correct nav active state and role-based nav items (admin vs non-admin)

## 2. Tables and status badges

- [x] 2.1 Add token-based CSS for `.entries-table` (or generalize to a shared `.admin-table` class) covering borders, spacing, row hover, header styling
- [x] 2.2 Add/verify `.status-*` badge styles using token colors, extending the color set with success/warning as needed
- [x] 2.3 Apply table/badge classes consistently across list views (entries, users, permissions, media, redirects)

## 3. Buttons

- [x] 3.1 Define secondary/ghost and danger button variant classes in `admin.css` alongside the existing primary button style
- [x] 3.2 Apply the danger variant to destructive actions (delete user, restore backup, remove redirect, etc.)
- [x] 3.3 Apply the secondary/ghost variant to non-primary actions (cancel, view-only links styled as buttons)

## 4. Icons

- [x] 4.1 Create `templates/admin/_icons/` with a small set of inline SVG partials for nav items and common actions (edit, delete, add)
- [x] 4.2 Include icons in the shared header nav and in relevant list/action buttons

## 5. Dark mode

- [x] 5.1 Add `@media (prefers-color-scheme: dark)` block in `admin.css` redefining existing color custom properties
- [x] 5.2 Spot-check contrast of text, buttons, and status badges in dark mode

## 6. Content width for tables

- [x] 6.1 Add a `.admin-main--wide` modifier (or equivalent) with a wider `max-width` for table-heavy views
- [x] 6.2 Apply the modifier to entries, users, and permissions list templates

## 7. Verification

- [x] 7.1 Manually walk through all 18 admin screens confirming consistent header, tables, buttons, and no visual regressions
- [x] 7.2 Confirm `prefers-reduced-motion` and keyboard-shortcut behavior are unaffected
- [x] 7.3 Run existing test suite to confirm no template rendering breakage
