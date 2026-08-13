## Context

The admin UI ships all of its styling in `public/assets/css/admin.css` — a hand-rolled stylesheet with a small token system at the top of the file (lines 16–21):

```
--stead-space-1: 0.25rem;
--stead-space-2: 0.5rem;
--stead-space-3: 0.75rem;
--stead-space-4: 1rem;
--stead-space-5: 1.5rem;
--stead-space-6: 2rem;
```

The two rules that govern form input and button padding today (around lines 64–85):

```css
input {
    width: 100%;
    max-width: 22rem;
    padding: var(--stead-space-2);              /* 0.5rem all sides */
    ...
}

button,
a.button {
    display: inline-block;
    padding: var(--stead-space-2) var(--stead-space-4);  /* 0.5rem vertical, 1rem horizontal */
    ...
}
```

A mirror pair lives in the bottom-of-page `.admin-account` block (around lines 215–229), overriding `button` background/color/border-color for the sticky account bar. (Earlier design notes referenced lines 546–575, but those are the `prefers-color-scheme: dark` overrides, not `.admin-account`.) The `.admin-account button` rule does not set padding — it inherits from the base `button` rule — and there is no `.admin-account input` rule at all. Issue #4 reports that the resulting visual rhythm between stacked fields and between buttons feels cramped.

Because everything already routes through the spacing tokens and the rules are concentrated in a handful of selectors, this is a token-bump change, not a layout refactor.

## Goals / Non-Goals

**Goals:**
- Increase vertical padding on `input`, `button`, and `a.button` by stepping up one rung on `--stead-space-*` (vertical only; horizontal stays).
- Apply the same bump to the `.admin-account` overrides so the footer stays in step with the rest of the form chrome.
- Stay within the existing token scale — no one-off hardcoded values.
- Preserve hover, focus, motion, dark mode, and mobile breakpoint behavior.

**Non-Goals:**
- Changing horizontal padding.
- Restructuring forms into grid/flex layouts.
- Touching the public site, installer, or any Twig template.
- Introducing a new spacing step (`--stead-space-2-5` etc.) — step one rung, that's all.

## Decisions

1. **Step vertical padding from `--stead-space-2` (0.5rem) to `--stead-space-3` (0.75rem).**
   - Rationale: the existing scale already has a clear next rung; the issue calls for a *modest* increase, and a single step (~50% more vertical room) is exactly that. Two steps (`--stead-space-4` = 1rem) would be a noticeable jump and risks looking like a layout overhaul.
   - Alternatives considered:
     - Hardcode `0.65rem` — would break the "use shared tokens" rule that the `admin-shell` spec enshrines.
     - Add a `--stead-space-2-5` step — over-engineering for one change; revisit only if multiple surfaces need it.
     - Bump by two steps (`--stead-space-4`) — overkill per the issue's "keep it subtle" guidance.

2. **Leave horizontal padding untouched.**
   - The complaint is vertical rhythm. Buttons at `var(--stead-space-2) var(--stead-space-4)` and inputs at `var(--stead-space-2)` are already wide enough; widening would only push dense layouts (e.g. the `.admin-account` bar) sideways.

3. **Mirror the same change in the `.admin-account` overrides.**
   - Rationale: those overrides exist to re-style the bottom account bar; if the main chrome gets taller, the footer must too or it will look shrunken by comparison.
   - Alternatives considered:
     - Drop the `.admin-account` overrides and let them inherit — would change other properties too (border radius, font weight) and broaden the diff beyond the issue.

4. **Edit the file in place; no build step, no new file.**
   - `admin.css` is served as-is. A new file would require touching the Twig layout to load it; the diff for that is bigger than the actual change.

## Risks / Trade-offs

- **Slightly taller forms push more page content down** → only affects vertical scroll position on long forms; no horizontal overflow, no overlap with the `.admin-account` sticky bar (it uses flex layout with `gap`).
- **The `.admin-account` bar is narrow; taller buttons there reduce headroom** → the bar already has `gap: var(--stead-space-2)` and generous outer padding; the 0.25rem extra height still fits comfortably on mobile.
- **Future spacing work might re-introduce cramped values if each control is hardcoded again** → mitigated by the new `admin-shell` requirement that vertical rhythm on form controls must use the shared spacing tokens.

## Migration Plan

- Land the change in `public/assets/css/admin.css` as a single small PR.
- No data migration, no cache bust concern beyond standard browser reload (the file is served with a content hash by the project's existing asset pipeline).
- Rollback: revert the file — no downstream effects.

## Open Questions

None. The fix is bounded to three CSS rules using existing tokens.
