## 1. Front controller gating

- [x] 1.1 Add an `installed.lock` presence check at the top of `public/index.php`, before `Application::bootstrap()` is called
- [x] 1.2 When absent, dispatch to a minimal installer kernel/route table instead of the normal `Kernel`/`Routes`
- [x] 1.3 When present, redirect `/install/*` requests to `/admin` (404 for non-page installer endpoints) and proceed through normal bootstrap for everything else

## 2. Installer routes & wizard shell

- [x] 2.1 Define `/install`, `/install/database`, `/install/admin`, `/install/sample-data`, `/install/complete` routes (GET+POST as needed)
- [x] 2.2 Build installer Twig layout matching admin-shell visual language, with step indicator
- [x] 2.3 Enforce step order: redirect to the database step if a later step is requested before its prerequisite succeeded (track progress in session, not `.env`)

## 3. Database step

- [x] 3.1 Build database connection form (driver, host, port, database, username, password)
- [x] 3.2 On submit, construct an in-memory `Configuration` from posted values and attempt a `Connection` + trivial query, reusing `Validator`'s field checks
- [x] 3.3 On success, hold validated settings in session and advance to the admin-user step; on failure, re-render with an actionable error that omits the password

## 4. Admin user step

- [x] 4.1 Build admin user form (email, password, confirm password)
- [x] 4.2 Validate email format and existing password requirements; on failure, re-render with field-level errors
- [x] 4.3 On success, hold the admin user's confirmed data in session and advance to the sample-data step (account is not created yet — DB isn't configured on disk until step 5)

## 5. Config generation & migration run

- [x] 5.1 Write `.env` from the session's confirmed database settings (mirroring `.env.example` keys: `DB_*`, `APP_ENV`, `SESSION_NAME`), leaving `config/app.yaml` untouched
- [x] 5.2 Surface a clear, path-specific error (not a generic 500) if `.env` cannot be written due to permissions, and do not proceed
- [x] 5.3 Run the migration runner in-process against the newly configured connection
- [x] 5.4 Create the admin account via `UserRepository::create()` using the session's confirmed admin data, assigned the administrator role

## 6. Sample data step

- [x] 6.1 Build sample-data opt-in/skip form
- [x] 6.2 On opt-in, invoke the existing `--seed` seed routine in-process
- [x] 6.3 Both opt-in and skip advance to the completion step

## 7. Completion & lock

- [x] 7.1 On the completion step, create `installed.lock` using an exclusive-create file write (first writer wins)
- [x] 7.2 If `installed.lock` already exists at this point (concurrent completion), skip re-running migrations/seed and redirect straight to `/admin/login`
- [x] 7.3 Clear installer wizard session state and redirect to `/admin/login`

## 8. Dev tooling & docs

- [x] 8.1 Add `docker-compose.yml` (PHP + MySQL + nginx) at repo root for prod-parity local testing
- [x] 8.2 Update root `README.md` with a 5-minute cPanel-style install walkthrough (extract ZIP → visit site → wizard → done)

## 9. Smoke tests

- [x] 9.1 Smoke test: fresh checkout with no `.env`/`installed.lock` → complete wizard against SQLite or MySQL → confirm working admin login
- [x] 9.2 Smoke test: complete wizard → revisit `/install` → confirm redirect to `/admin` and no re-run
- [x] 9.3 Smoke test: submit bad DB credentials → confirm actionable error and no `.env` written
- [x] 9.4 Smoke test: full flow against `docker compose` MySQL service (runs when `DB_HOST` is set, skipped otherwise)