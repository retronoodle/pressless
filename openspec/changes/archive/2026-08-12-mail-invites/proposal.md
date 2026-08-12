## Why

Outbound mail is currently unimplemented, and WordPress's default reliance on PHP's `mail()` is a named anti-pattern in this project (routinely spam-flagged, no delivery guarantees). Roles & permissions (Phase 7) let an admin grant an editor/author role, but there's still no way to actually get a new user into the system — admins can only create accounts directly. An SMTP-based mail transport plus an email invite flow closes that gap and unblocks reliable transactional email for future phases (password reset, notifications).

## What Changes

- Add an SMTP mail transport (custom, config-driven: host, port, encryption, auth) as the sole outbound mail path — no PHP `mail()` fallback.
- Add mail settings storage + an admin UI to configure SMTP and send a test email.
- Add an invite flow: admin generates a single-use, expiring token tied to an email + role, sends it via the mail transport.
- Add a public invite-acceptance page: token validates, invitee sets a password, account is created (or activated) with the assigned role.
- **BREAKING**: none to existing schemas/behavior — this is additive (new `mail_settings` and `invites` tables, new routes). Existing admin user-creation flow is unchanged.

## Capabilities

### New Capabilities
- `mail`: SMTP transport configuration and sending (settings storage, test-send, the transport itself as a service other features send through).
- `invites`: invite token lifecycle (generate, email, validate, accept, expire) and the public acceptance page.

### Modified Capabilities
(none — no existing capability's requirements change; invites is additive alongside the existing direct admin user-creation path in `roles-permissions`.)

## Impact

- New DB tables: `mail_settings` (single-row config, or reuse `settings` table pattern), `invites` (token, email, role_id, expires_at, accepted_at).
- New admin routes: mail settings page, invite-send form.
- New public route: `/invite/accept/{token}`.
- New `src/Mail/` namespace: SMTP transport, message builder.
- Depends on `src/Auth` (User/Role models) from Phase 7 and the migrations runner from Phase 1.
- No new Composer dependencies — SMTP transport is hand-rolled per PRD §4 ("from-scratch core").
