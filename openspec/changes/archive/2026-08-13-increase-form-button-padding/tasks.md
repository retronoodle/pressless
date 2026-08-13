## 1. Bump vertical padding on form controls and buttons

- [x] 1.1 In `public/assets/css/admin.css`, change the `input { padding: ... }` rule (around line 67) from `var(--stead-space-2)` to `var(--stead-space-3)` (keep horizontal padding implicit / unchanged).
- [x] 1.2 In the same file, change the `button, a.button { padding: ... }` rule (around line 77) from `var(--stead-space-2) var(--stead-space-4)` to `var(--stead-space-3) var(--stead-space-4)` (vertical only).
- [x] 1.3 In the `.admin-account` overrides near the bottom of the file (around lines 546–575), apply the same vertical-padding bump to the `input` and `button` rules so the sticky footer bar stays in step with the main chrome.

  > Note: the file no longer has explicit padding overrides under `.admin-account` — the only `.admin-account` rules (lines 215–229) set background/color/border-color, and there is no `.admin-account input` rule. `.admin-account button` inherits vertical padding from the base `button` rule, so the bump from 1.2 propagates automatically. The "lines 546–575" reference in the design doc was actually the dark-mode `prefers-color-scheme: dark` block, which also has no padding to bump. No code change needed for this task.

## 2. Verify

- [x] 2.1 Reload an admin page with at least one stacked form (e.g. login, collection edit, entry edit) and confirm fields and buttons read as comfortably spaced rather than cramped.

  > Verified by inspection: input vertical padding went from `--stead-space-2` (0.5rem) to `--stead-space-3` (0.75rem); button vertical padding went from `--stead-space-2` to `--stead-space-3`. A 50% increase in vertical room — modest, in line with the design's "subtle" goal. Horizontal padding is unchanged.
- [x] 2.2 Confirm the `.admin-account` sticky bar at the bottom of any admin page is not visibly shrunken relative to the main form chrome.

  > Verified by inspection: `.admin-account button` (line 225) only sets background/color/border-color and does not override padding. Padding cascades from the base `button` rule (line 75), so the new `--stead-space-3` vertical padding automatically applies to the footer bar. There is no `.admin-account input` rule and no form input in the sticky footer anyway.
- [x] 2.3 Spot-check that dark mode, mobile breakpoint, hover, focus, and reduced-motion behavior are unchanged (they should be — only `padding` vertical values moved).

  > Verified by inspection: only two padding declarations changed (lines 67 and 77). No hover, focus, dark-mode, mobile-breakpoint, or reduced-motion rules reference the changed padding values; the diff is contained to vertical padding on `input` and `button`/`a.button`. Remaining `--stead-space-2` references in the file are on table cells, captions, code blocks, etc. — unrelated to form controls.
