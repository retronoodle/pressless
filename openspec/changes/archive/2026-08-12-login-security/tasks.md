## 1. Schema & config

- [x] 1.1 Migration: `login_attempts` table (id, email, ip_address, succeeded, created_at; indexes on `(email, created_at)` and `(ip_address, created_at)`) — mysql + sqlite pair
- [x] 1.2 Add `login:` config section to `config/app.yaml` (`max_attempts`, `window_seconds`, `lockout_base_seconds`, `lockout_max_seconds`) with defaults 5 / 900 / 60 / 3600

## 2. Login throttle service

- [x] 2.1 Build `src/Auth/LoginAttemptRepository`: record an attempt (email, ip, succeeded), count recent failures for an identifier within the window, clear an email's failures, prune rows older than `2 * window_seconds` inline on write
- [x] 2.2 Build `src/Auth/LoginThrottle`: `isLocked(email, ip): bool`, `recordFailure(email, ip): void`, `recordSuccess(email): void`, computing exponential backoff per design.md
- [x] 2.3 Wire `LoginThrottle` into `LoginController::login()`: check lockout before calling `AuthenticationService::attempt()`; record failure/success after the outcome

## 3. UI

- [x] 3.1 Add lockout message state to `templates/login.twig`, generic and indistinguishable from the existing invalid-credentials message

## 4. Verification

- [x] 4.1 Smoke test: exceed the failed-login threshold for an email → confirm lockout response → confirm backoff clears after the window elapses
- [x] 4.2 Smoke test: exceed the failed-login threshold from one IP across different (including nonexistent) emails → confirm IP lockout triggers
- [x] 4.3 Smoke test: successful login after some failed attempts → confirm the email's failure count resets
