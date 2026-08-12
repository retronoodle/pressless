## Context

No mail capability exists yet. Roles & permissions (Phase 7, `roles-permissions` spec) added `roles`/`permissions` and `role_id` on `users`, but the only way to create a user is direct admin creation — there's no self-service or email-mediated onboarding. Migrations ship as paired `.mysql.sql`/`.sqlite.sql` files under `database/migrations/`, dated in the filename. PRD §4 mandates a from-scratch core with Composer only where it earns it — SMTP client code is hand-rolled, not `symfony/mailer` or PHPMailer.

## Goals / Non-Goals

**Goals:**
- Config-driven SMTP transport (host/port/encryption/auth) as the only outbound mail path.
- Admin-configurable mail settings with a test-send button, stored in DB (editable without redeploying).
- Token-based invite: admin picks email + role → token generated + emailed → invitee sets password on a public page → account created with that role.
- Fault-visible sending: a failed send surfaces an error to the admin, not a silent drop.

**Non-Goals:**
- No email templating system / marketing email — transactional only (invite now; password reset etc. are future phases reusing this transport).
- No queue/async delivery — sends are synchronous for v1 (mirrors PRD's "SMTP-first" scope, not "background job infra").
- No multi-provider abstraction (e.g. pluggable API-based providers like SES/Postmark) — plugin `namespaced route`/lifecycle capabilities (Phase 15+) are the extension point later if needed.

## Decisions

- **Hand-rolled SMTP client over a library.** PRD explicitly rules out incidental Composer deps; SMTP protocol (EHLO/STARTTLS/AUTH/DATA) is well-bounded and testable against a local fake server or `symfony/mailer`'s test transport pattern isn't available without pulling the package. Alternative considered: `symfony/mailer` — rejected, contradicts §4's dependency stance for something this scoped.
- **Mail settings as a dedicated single-row table (`mail_settings`)** rather than waiting for the general `settings` table (Phase 13). Alternative: generic key-value `settings` table now — rejected because Phase 13 doesn't exist yet and inventing it here would scope-creep this change; a small dedicated table is cheap and Phase 13 can migrate it later if it wants a unified store.
- **Invites as a separate `invites` table**, not a `status` flag on `users`. An invite must exist before a `users` row does (invitee has no account yet), so it can't live on `users`. Token is a random 32-byte value, stored hashed (like password reset tokens elsewhere) to avoid a leaked-DB token replay.
- **Synchronous send on the request thread.** Invite/test-send happens inline; PRD doesn't mention a job queue anywhere in scope, and admin actions here are low-frequency (not a hot path needing async).
- **Invite expiry**: fixed TTL (e.g. 7 days), checked at acceptance time; expired tokens are rejected but not proactively purged (cheap to filter on read; a prune step can be added if the table grows large — out of scope for v1).

## Risks / Trade-offs

- [Hand-rolled SMTP client has edge cases (odd server auth mechanisms, TLS quirks)] → Scope to the common path (LOGIN/PLAIN auth, STARTTLS on 587, implicit TLS on 465); document unsupported configs rather than trying to cover every SMTP server.
- [Synchronous send blocks the request if the SMTP server is slow/down] → Reasonable timeout on the socket connection; failure surfaces as an admin-visible error rather than hanging indefinitely.
- [Token stored hashed means a lost/regenerated token can't be recovered] → Admin can re-issue (regenerate) an invite for the same email, invalidating the old token.
- [No queue means a mail outage blocks invite-sending, not just notifications] → Acceptable for v1 per PRD scope; admin sees the failure immediately and can retry.

## Migration Plan

- New migration `mail_settings` table (single row, upserted).
- New migration `invites` table (id, email, role_id FK, token_hash, expires_at, accepted_at, created_by, created_at).
- Additive only — no changes to existing tables/columns; no rollback complexity beyond dropping the two new tables.

## Open Questions

- Exact invite TTL value (defaulting to 7 days unless the user wants shorter/longer) — not a blocker, easy to change later.
