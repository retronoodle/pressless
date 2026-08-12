## Context

`LoginController::login()` (`src/Http/Controller/LoginController.php`) calls `AuthenticationService::attempt()` (`src/Auth/AuthenticationService.php`) with no attempt limit — any number of guesses can be submitted. `attempt()` already equalizes timing for unknown emails via a fixed bcrypt hash and returns a single generic error string for every failure mode (unknown email, inactive account, wrong password), so the existing "don't leak account existence" contract must extend to lockout responses too. No rate-limiting, throttling, or attempt-tracking code exists anywhere in the codebase. Migrations are paired `.mysql.sql`/`.sqlite.sql` files under `database/migrations/`, dated in the filename; latest is `20260812140002_invites`. Config lives in a single `config/app.yaml` with `defaults`/`development`/`production` sections.

## Goals / Non-Goals

**Goals:**
- Track failed login attempts per email and per IP address.
- Lock out an identifier (email or IP) after N failures within a rolling window.
- Escalate lockout duration with exponential backoff on repeated lockouts, capped at a maximum.
- Lockout clears automatically once the backoff window elapses — no admin unlock action needed.
- Lockout state is indistinguishable from a normal failed-credentials response in terms of what it reveals about account existence.

**Non-Goals:**
- No CAPTCHA or third-party bot-detection integration.
- No admin UI to view/clear lockouts — this is a v1 automatic-only mechanism; an admin-visible attempt log can be a later phase if needed.
- No distributed/shared-cache rate limiting (e.g. Redis) — a DB table is sufficient at the expected scale (single small-to-medium site, not a high-traffic API).
- No account-level "permanent" lockout requiring password reset — every lockout is time-bounded.

## Decisions

**Separate `LoginThrottle` service, not a change to `AuthenticationService::attempt()`.** `attempt()` has one caller (`LoginController::login()`) and a well-defined `?User` return contract already relied on for the timing-equalization behavior. Rather than overload its return type or add exceptions for a third outcome (locked), `LoginThrottle` is checked by the controller *before* calling `attempt()`, and told the outcome *after*. This keeps `AuthenticationService` untouched and the new logic isolated and independently testable. Alternative considered: fold throttling into `AuthenticationService` — rejected, it conflates two concerns (credential verification vs. abuse prevention) and would require changing a return type with an existing caller.

**Track attempts in a dedicated `login_attempts` table, one row per attempt.** Columns: `id`, `email` (as submitted, lowercased), `ip_address`, `succeeded` (bool), `created_at`. Indexes on `(email, created_at)` and `(ip_address, created_at)` for the window queries. Alternative considered: a rolling counter row per identifier (e.g. `login_throttle(identifier, count, first_attempt_at)`) — rejected because an append-only log is simpler to reason about, self-prunes naturally by age, and a full row per attempt costs little at login-page volume.

**Failures are recorded for every failed attempt, including unknown emails.** This is required for the "don't leak account existence" contract: if only real accounts accumulated failures, an attacker could distinguish valid from invalid emails by whether lockout ever kicks in. Recording against the submitted email string (whether or not it resolves to a user) closes that gap.

**Lockout check runs before credential verification.** If either the email or the IP is currently locked out, `LoginController` returns the generic lockout message immediately, without calling `AuthenticationService::attempt()` — avoids doing bcrypt work (and any timing signal) for a request that's going to be rejected anyway.

**Exponential backoff formula:** once failures within the window reach `max_attempts`, lockout duration = `min(lockout_base_seconds * 2^(failures - max_attempts), lockout_max_seconds)`, counted from the most recent failure. A fresh window of failures (after the previous lockout has fully expired and enough time has passed to age out old rows) restarts the count. Config defaults: `max_attempts: 5`, `window_seconds: 900` (15 min), `lockout_base_seconds: 60`, `lockout_max_seconds: 3600` (1 hr) — chosen as reasonable defaults for a small-site admin login, overridable in `config/app.yaml`.

**Successful login clears that email's recorded failures but not the IP's.** Resetting on success is standard practice and avoids a legitimate user being penalized by their own earlier typos. IP failures are left alone since a shared IP (office, NAT) could otherwise be cleared by one successful login while other attempts against it continue — the IP-side lockout is meant to catch abuse from that network path, not to trust it once any login on it succeeds.

**Pruning happens inline, not via a separate scheduled job.** On each recorded attempt, rows older than `2 * window_seconds` for that email/IP are deleted in the same call. No `bin/*` cron command needed for v1; the table stays small because every write is also a light prune.

## Risks / Trade-offs

- [A shared IP (office NAT, VPN) can be locked out by one bad actor's repeated failures, blocking legitimate users on the same network] → Accepted trade-off for v1: PRD explicitly asks for per-IP lockout, a shared-IP false positive is rare for the target audience (small sites), and it self-clears within `lockout_max_seconds`.
- [Attempt log table grows under sustained attack before inline pruning catches up] → Inline pruning runs on every write and only removes rows outside `2 * window_seconds`, bounding growth to roughly one window's worth of attempts even under sustained load.
- [Exponential backoff with no cap could lock a user out for a very long time] → `lockout_max_seconds` caps escalation.

## Migration Plan

- New migration: `login_attempts` table (mysql + sqlite pair), additive only.
- New `login:` config section in `config/app.yaml` under `defaults`, with sane built-in values — no required operator action.
- No changes to existing tables, no rollback complexity beyond dropping the new table.

## Open Questions

- Exact default threshold/window/backoff values (5 attempts / 15 min / 60s base / 1hr cap) are a starting point, easy to tune later — not a blocker.
