## Why

Login has no attempt tracking today — `AuthenticationService::attempt()` checks credentials and returns, with no limit on retries. A brute-force or credential-stuffing script can hit `/admin/login` indefinitely. PRD §6 requires "login rate limiting + lockout after repeated failed attempts," and Phase 9 is the last gap in the auth surface before the web installer (Phase 10) ships a public-facing install path.

## What Changes

- Add failed-login attempt tracking, keyed by both the submitted email and the requesting IP address.
- Lock out further attempts for an identifier once it exceeds a configurable failure threshold within a rolling window, with exponential backoff on repeated lockouts.
- Login page shows a generic lockout state (no indication of whether the account exists) when either the email or IP is currently locked out.
- Backoff clears automatically once the lockout window elapses — no manual unlock step in v1.
- **BREAKING**: none — additive only. `AuthenticationService::attempt()`'s signature and existing callers are unchanged; throttling is enforced in `LoginController` before `attempt()` is called.

## Capabilities

### New Capabilities
(none — this extends the existing login flow rather than introducing a new capability)

### Modified Capabilities
- `authentication`: adds failed-attempt tracking and lockout behavior to the session-backed login requirement; login errors must additionally cover the locked-out case without leaking account existence.

## Impact

- New DB table: `login_attempts` (per-attempt record, email + IP + outcome + timestamp).
- New `src/Auth/LoginThrottle` service (check lock state, record outcome), used by `LoginController::login()` ahead of `AuthenticationService::attempt()`.
- New config section in `config/app.yaml` (`login:` — max attempts, window, backoff base/cap).
- Template change: `templates/login.twig` gains a lockout message state.
- No changes to `AuthenticationService`, `users`, or `sessions` schema.
