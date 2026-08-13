## Why

Form inputs and buttons in the admin UI sit too close together vertically — the default spacing reads as cramped and uncomfortable, especially when a form has several stacked fields or multiple actions in a row. GitHub issue #4 reports this and calls for a modest increase without a larger layout overhaul. Because the admin already uses a shared spacing scale (`--stead-space-*`), the fix is a token-level adjustment, not a layout change.

## What Changes

- Increase vertical padding on `input`, `button`, and `a.button` in `public/assets/css/admin.css` so the spacing between stacked fields and between buttons feels comfortable, not crowded.
- Bump the vertical padding on the same selectors used inside `.admin-account` (sticky bottom account bar) so the footer stays visually consistent with the rest of the form chrome.
- Keep horizontal padding roughly where it is — only the vertical rhythm changes.
- Use the existing `--stead-space-*` tokens rather than introducing new values; pick the next step up on the scale so the change stays subtle and in keeping with the rest of the admin.
- No changes to markup, JS, or any Twig template.

## Capabilities

### New Capabilities

(None — this is a refinement of an existing capability.)

### Modified Capabilities

- `admin-shell`: add a requirement covering vertical rhythm for form controls and buttons, so future spacing work stays anchored to the shared tokens and does not reintroduce cramped defaults via per-rule hardcoded values.

## Impact

- `public/assets/css/admin.css` — single-token bump on three rules (`input`, `button`/`a.button`, plus the same selectors under `.admin-account`).
- Visual impact: existing admin pages render with slightly taller inputs and slightly taller buttons; no layout reflow, no template or JS changes.
- No impact on the public-facing site, installer, mobile breakpoint behavior, dark mode, or reduced-motion handling — those rules are unaffected.
