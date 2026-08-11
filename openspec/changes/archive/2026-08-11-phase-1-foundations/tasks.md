<!--
Planned session split (added 2026-08-11 to keep work resumable across sessions):
  Session A: finish Section 1 (1.5 phpunit/phpstan configs) + Sections 2-3 (config + DB + initial migration + tests).
  Session B: Sections 4-5 (HTTP routing + authentication).
  Session C: Sections 6-7 (Twig admin shell + CLI `bin/serve` + seed).
  Session D: Section 8 (E2E smoke test + final PHPUnit/PHPStan pass).
Last resume point: Sessions A and B complete (Sections 1-5). HTTP routing and authentication are implemented and verified against a live `php -S` run: unauthenticated redirect, login, protected shell, logout, 404/405, static-asset handoff, and traversal refusal all confirmed. Next: Session C (Sections 6-7).
Note for Session C: `Pressless\View\Renderer` is the seam for task 6.1 — the Twig renderer implements it and replaces `SimpleRenderer` in `Http\Routes::register()`; controllers do not change. Templates needed: `login` and `admin`.
-->

## 1. Project and dependency setup

- [x] 1.1 Create the PHP 8.2+ Composer manifest, `Pressless\\` PSR-4 namespace, project scripts, and the Phase 1 directory layout.
- [x] 1.2 Add the focused runtime and development dependencies for Twig, HttpFoundation, Console, dotenv, YAML parsing, Monolog, PHPUnit, and PHPStan.
- [x] 1.3 Add the shared application bootstrap and project-root resolver used by both web and CLI entry points.
- [x] 1.4 Add safe exception handling and structured logging boundaries that never include credentials, passwords, or session payloads.
- [x] 1.5 Add PHPUnit and PHPStan configuration plus documented contributor commands for tests and static analysis.

## 2. Configuration

- [x] 2.1 Add the example environment file and YAML configuration defaults for application, database, paths, sessions, and logging.
- [x] 2.2 Implement dotenv loading with exported environment values taking precedence over local `.env` values.
- [x] 2.3 Implement YAML loading and normalized configuration access with project-relative path resolution.
- [x] 2.4 Implement startup validation for the supported database driver, required connection values, environment, and runtime paths.
- [x] 2.5 Update ignore rules and setup instructions so local environment files and credentials cannot be committed.
- [x] 2.6 Add unit tests for precedence, path resolution, invalid values, and secret-safe configuration errors.

## 3. Database foundation

- [x] 3.1 Implement the thin PDO connection and query wrapper with prepared statements, transactions, consistent exception behavior, and MySQL/MariaDB validation.
- [x] 3.2 Implement versioned SQL migration discovery, ordering, transaction handling, and applied-version tracking.
- [x] 3.3 Add the initial migration for `users`, `sessions`, `collections`, `entries`, `entry_values`, `media`, and `revisions`, including keys, constraints, timestamps, indexes, and collection JSON schema storage.
- [x] 3.4 Implement the explicit dependency-safe schema reset operation used by `--fresh`.
- [x] 3.5 Add a `bin/migrate` entry point for applying pending migrations without starting the web server.
- [x] 3.6 Add database tests for parameter binding, transaction rollback, migration idempotence, failed migration handling, schema relationships, and duplicate identity constraints.
- [x] 3.7 Add SQLite driver support to the connection layer with per-driver DSN handling and `PRAGMA foreign_keys=ON` for SQLite connections.
- [x] 3.8 Add a driver-aware migration runner that discovers the per-driver migration file and ships a SQLite-portable initial schema alongside the MySQL/MariaDB one.
- [x] 3.9 Add SQLite database tests for parameter binding, transaction rollback, migration idempotence, foreign-key enforcement, and duplicate identity constraints.

## 4. HTTP entry point and routing

- [x] 4.1 Implement the public front controller using HttpFoundation request and response objects.
- [x] 4.2 Implement the isolated custom route matcher with method/path matching, parameter extraction, and explicit route registration.
- [x] 4.3 Evaluate `nikic/fast-route` against the initial route set and record whether the custom matcher remains the Phase 1 implementation.
- [x] 4.4 Register the initial login, logout, and protected admin routes with deterministic 404 and 405 responses.
- [x] 4.5 Add the PHP built-in-server router handoff so public files are served directly and dynamic paths reach the front controller.
- [x] 4.6 Add routing tests for dispatch, method distinction, parameters, unknown paths, unsupported methods, and source-file protection.

## 5. Authentication and sessions

- [x] 5.1 Implement user and session persistence services against the Phase 1 schema.
- [x] 5.2 Implement a native PHP session handler backed by the sessions table with expiry and revocation support.
- [x] 5.3 Implement bcrypt password creation and verification through a dedicated authentication service.
- [x] 5.4 Implement login, logout, session regeneration, secure cookie configuration, and protected-route authentication middleware.
- [x] 5.5 Add login and logout request handlers with generic invalid-credential responses and safe redirect behavior.
- [x] 5.6 Add authentication tests for hashing, valid and invalid login, inactive users, session expiry, logout invalidation, redirects, and cookie settings.

## 6. Twig admin surface

- [x] 6.1 Implement the Twig renderer with configured template paths and autoescaping enabled.
- [x] 6.2 Create the server-rendered login template with labeled controls, safe error display, and form method/action wiring.
- [x] 6.3 Create the shared admin layout, navigation placeholders, empty-state components, and minimal hand-rolled CSS.
- [x] 6.4 Implement the authenticated `/admin` handler and connect it to the authentication guard and Twig shell.
- [x] 6.5 Add view and HTTP integration tests for escaped output, login rendering, protected shell access, empty states, and controlled template failures.

## 7. Evaluator command and seed data

- [x] 7.1 Implement the Symfony Console application and executable `bin/serve` command with configurable host and port.
- [x] 7.2 Wire `--fresh` to reset the schema and rerun migrations before the server process starts.
- [x] 7.3 Add the sample seed data file and idempotent seeder for a development administrator and empty sample collections.
- [x] 7.4 Wire `--seed` to the seeder, gate it outside production, and provide safe setup output for evaluator credentials/configuration.
- [x] 7.5 Add command-level tests for normal startup validation, fresh reset ordering, repeatable seeding, production refusal, and nonzero failures.

## 8. End-to-end verification

- [x] 8.1 Add the install-to-login smoke test against a clean MySQL/MariaDB test database.
- [x] 8.2 Verify the evaluator sequence: fresh checkout, dependency install, migration/reset, seed, server start, unauthenticated redirect, successful login, and empty admin shell.
- [x] 8.3 Run the documented PHPUnit and PHPStan checks and resolve all Phase 1 failures.
