## 1. Schema

- [x] 1.1 Migration: `mail_settings` table (single-row config: host, port, encryption, username, password, updated_at) — mysql + sqlite pair
- [x] 1.2 Migration: `invites` table (id, email, role_id FK, token_hash, expires_at, accepted_at, created_by, created_at) — mysql + sqlite pair

## 2. SMTP mail transport

- [x] 2.1 Build `src/Mail/SmtpTransport`: connects, STARTTLS/implicit TLS, LOGIN/PLAIN auth, sends a message, surfaces connection/auth/send errors
- [x] 2.2 Build `src/Mail/Message` (to, subject, body, headers)
- [x] 2.3 Build `src/Mail/MailSettingsRepository` (load/save single-row settings, password never re-serialized to callers as plaintext after save)
- [x] 2.4 Wire transport to read settings from `MailSettingsRepository`

## 3. Mail settings admin UI

- [x] 3.1 Build mail settings admin page (form: host, port, encryption, username, password)
- [x] 3.2 Implement save action (persist via `MailSettingsRepository`)
- [x] 3.3 Implement test-send action + button, showing success/error inline
- [x] 3.4 Smoke test: save SMTP settings → send test email → confirm delivery (or clear error if unreachable)

## 4. Invite flow

- [x] 4.1 Build `src/Invites/InviteRepository` (create, find by token hash, mark accepted, invalidate prior pending invite for an email)
- [x] 4.2 Build invite token generation (random token, store hash only, fixed TTL e.g. 7 days)
- [x] 4.3 Build admin invite-send form (email + role) → validation (reject existing user email) → create invite → send email via mail transport with acceptance link
- [x] 4.4 Build public invite-acceptance route/page: validate token (exists, unexpired, unused) → set-password form
- [x] 4.5 Implement acceptance submission: validate password policy, create `users` row with invited role, mark invite accepted, log in or redirect to login
- [x] 4.6 Handle invalid/expired/already-used token with generic, non-leaking error states

## 5. Verification

- [x] 5.1 Smoke test: invite a user → accept invite → confirm account created with correct role
- [x] 5.2 Smoke test: attempt to reuse an accepted invite token → confirm rejected
- [x] 5.3 Smoke test: attempt to accept an expired invite token → confirm rejected
