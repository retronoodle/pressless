## Context

The repository currently contains the product requirements and project metadata but no runnable PHP application. Phase 1 is a cross-cutting foundation: it must establish a request lifecycle, configuration boundary, database contract, authentication boundary, admin rendering, and evaluator CLI without introducing a full framework.

The target runtime is PHP 8.2+ on MySQL or MariaDB, including shared and cPanel-style hosting. The core must remain small and hand-rolled. Twig is the template engine, Symfony HttpFoundation and Console provide focused HTTP and CLI primitives, dotenv loads environment values, and Monolog provides structured application logging. ORM layers, Symfony Framework, Laravel, and admin template kits remain out of scope.

## Goals / Non-Goals

**Goals:**

- Make a fresh checkout installable with Composer and runnable through `bin/serve`.
- Provide validated configuration, a safe PDO boundary, repeatable migrations, and the Phase 1 schema.
- Support a complete development path: reset the database, seed an administrator, open the login page, authenticate, and view an empty admin shell.
- Establish stable contracts that later phases can use for collections, entries, media, revisions, and permissions.
- Keep production behavior compatible with ordinary LAMP hosting and avoid committing credentials or environment-specific state.

**Non-Goals:**

- Collection and entry CRUD, field-type implementations, public theme rendering, media uploads, revisions, invitations, or role permissions beyond the initial administrator boundary.
- A general-purpose plugin or hook system.
- A production installer wizard, update mechanism, API/headless mode, or multi-site support.
- Automatic database provisioning or an ORM.
- Admin visual polish beyond the initial layout, navigation placeholders, and useful empty states.

## Decisions

### Keep the application framework-free

Use a `Pressless\\` PSR-4 application namespace with a small bootstrap, explicit service construction, and a front controller. Use Symfony HttpFoundation only for request/response objects and Symfony Console for commands; do not adopt a framework kernel or service container. This preserves the product's small-core constraint and makes lifecycle behavior visible to contributors.

Alternative considered: Symfony Framework or Laravel. Both provide mature conventions but add lifecycle, configuration, and extension surfaces that conflict with the intentional thin-core boundary.

### Use Composer for focused dependencies

Declare the PHP runtime requirement and only the libraries named or implied by the PRD: Twig, Symfony HttpFoundation, Symfony Console, dotenv, YAML parsing, Monolog, PHPUnit, and PHPStan. Evaluate `nikic/fast-route` during implementation; retain the custom route matcher unless the evaluation shows a clear benefit without obscuring the route contract.

Alternative considered: implement every utility from scratch. That would increase security and maintenance risk for templating, HTTP semantics, CLI parsing, and logging without improving the product boundary.

### Make configuration layered and fail fast

Load process environment values first, then `.env` for local development without overriding explicitly exported values, then YAML application configuration, with documented environment overrides for deployment-sensitive values. Resolve paths relative to the project root, validate required database and application settings during bootstrap, and report configuration errors without printing secret values.

Alternative considered: PHP-only configuration. It is simple but less approachable for deployment and does not satisfy the YAML configuration requirement.

### Use a thin PDO boundary and SQL migrations

Expose prepared-query, transaction, and connection operations through a small PDO wrapper. Keep domain persistence in explicit repositories or services rather than an ORM. Store ordered SQL migration files and record successful versions in a `migrations` table; the runner applies each pending version once and fails before marking an unsuccessful migration complete.

The initial migration creates users, sessions, collections, entries, entry values, media, and revisions with foreign keys, timestamps, indexes, and nullable extension points needed by later phases. JSON schema data belongs on collections so Phase 2 can add typed fields without replacing the foundation. SQL migration files are written for MySQL/MariaDB; SQLite is supported via a parallel per-driver migration file so the production target keeps its native column types while local evaluation works without a server.

Alternative considered: Doctrine or an ORM query builder. It would hide SQL and add a substantial dependency surface, contrary to the stated database architecture.

### Support SQLite alongside MySQL/MariaDB

The connection layer accepts `mysql`, `mariadb`, and `sqlite` drivers, validates the DSN shape per driver, and enables SQLite foreign-key enforcement through `PRAGMA foreign_keys=ON` on each new connection. SQLite is intended for local evaluator paths; MySQL/MariaDB remains the production target. Migrations are stored in a driver-aware form so SQLite-specific syntax (JSON stored as TEXT, no engine/charset clauses, autoincrement via `INTEGER PRIMARY KEY`) does not leak into the MySQL track.

Alternative considered: SQLite-only migrations via a runtime MySQL→SQLite translator. Rejected because it hides syntax differences and complicates the migration contract; per-driver files keep each track explicit and readable.

### Use a custom route table behind one front controller

Route all dynamic requests through `public/index.php`, construct a normalized HttpFoundation request, match method and path against an explicit route table, and return an HttpFoundation response. Start with the admin routes required for login and the empty shell, plus deterministic 404 and method-not-allowed responses. Keep the matcher isolated so FastRoute can be substituted later if the evaluation warrants it.

Alternative considered: adopt FastRoute immediately. It is a reasonable option, but a small Phase 1 route set benefits from a transparent implementation and avoids making an evaluation decision before measuring the actual need.

**Evaluation outcome (task 4.3): the custom matcher remains the Phase 1 implementation.** Measured against the initial route set, FastRoute was not added as a dependency. The Phase 1 table is four routes (`GET`/`POST /admin/login`, `POST /admin/logout`, `GET /admin`), all static paths with no parameters. The custom matcher is 242 lines across `Route`, `Router`, and `RouteMatch`, and already provides the behavior the specs require: method/path matching, `{name}` parameter extraction, normalized paths, `HEAD` served by the `GET` handler, and a `RouteMatch` status that distinguishes not-found from method-not-allowed with an `Allow` list. FastRoute's principal advantage — regex chunking so large route tables match in near-constant time — has no measurable effect at four static routes, and adopting it would mean expressing the 405 case through its dispatcher constants rather than the project's own result type. The matcher stays isolated behind `Router::match()`, so substituting FastRoute later is a change to one class. Revisit when the table grows past roughly 50 routes or gains variable-heavy public patterns in Phase 3.

### Persist only authenticated sessions

`sessions.user_id` is `NOT NULL` in the Phase 1 schema, so an anonymous pre-login session has no valid row. The database session handler therefore skips the write when the encoded payload carries no user id, and the `sessions` table is a lifecycle record for authenticated sessions only. This keeps the schema unchanged and loses nothing that needs central expiry or revocation, since login regenerates the identifier before the first record is written.

The consequence is that no session state survives across requests before login. The originally requested path is therefore carried to the login page as a validated `redirect` query parameter rather than stashed in the session, and any future pre-login state (a CSRF token, for example) needs either a signed cookie or a nullable `user_id`.

Alternative considered: make `sessions.user_id` nullable so anonymous sessions persist. Rejected for Phase 1 because it would edit an already-applied migration and add rows with no lifecycle value.

### Use native sessions with database-backed lifecycle records

Use PHP's native session API and secure cookie settings, with a small session handler backed by the sessions table so sessions can be expired and invalidated centrally. Hash passwords with `password_hash` using bcrypt and verify with `password_verify`. Regenerate the session identifier after login, expose an authentication guard for protected routes, and destroy the session on logout.

Alternative considered: file-only PHP sessions. They are easy to enable but make the existing sessions table unused and are less predictable across shared-host deployments or future workers.

### Render the first admin surface with Twig and plain CSS

Use Twig templates with autoescaping enabled, a base admin layout, a login template, and an authenticated shell containing navigation placeholders and explicit empty states. Keep controllers responsible for request orchestration and pass simple view data; do not embed SQL or authentication logic in templates. Use hand-rolled CSS with no admin kit or frontend build requirement.

Alternative considered: an admin template kit or JavaScript SPA. Both add visual and operational complexity before the core content model exists and work against the calm, server-rendered product direction.

### Make `bin/serve` an evaluator command, not product runtime logic

Implement a Symfony Console command that performs requested preflight actions, then wraps `php -S` with a router script. `--fresh` resets only the application's known tables in dependency-safe order and reruns migrations, which works with hosting accounts that cannot drop a database. `--seed` is deterministic and idempotent, creates a development administrator and empty collections, and is refused or explicitly guarded for production environments.

Alternative considered: a separate collection of shell scripts. A typed command gives consistent option parsing, validation, and user-facing errors while keeping the dev-only behavior outside the web application.

### Test the evaluator path against MySQL/MariaDB

Use PHPUnit for unit tests around configuration, routing, password/session behavior, and migration bookkeeping, plus an integration smoke test that runs against a configured MySQL/MariaDB test database. Use PHPStan and the project's standard command wrappers for static checks. Do not substitute SQLite for database behavior that depends on MySQL/MariaDB semantics.

Alternative considered: browser-only manual verification. It would miss migration, authentication, and reset regressions and would not provide a repeatable acceptance gate.

## Risks / Trade-offs

- [Shared hosts vary in PHP extensions and CLI availability] → Validate required extensions during bootstrap/CLI startup, keep dependencies Composer-installable, and provide actionable errors.
- [SQLite and MySQL differ in JSON, autoincrement, and foreign-key behavior] → Keep migrations in per-driver files, enable SQLite foreign keys per connection, and document JSON storage as TEXT on SQLite vs JSON on MySQL.
- [Database-backed native sessions add setup and cleanup paths] → Keep the handler small, use parameterized queries and bounded expiry cleanup, and cover login/logout/revocation with integration tests.
- [A custom router can diverge from established HTTP behavior] → Limit its scope, test method/path/status cases, and isolate the matcher behind a small interface for later replacement.
- [The initial schema may constrain later content features] → Add explicit foreign keys, indexes, timestamps, and nullable/JSON extension points while deferring content behavior to later capability specs.
- [Development seeding can leak unsafe credentials into a deployment] → Gate seeding by environment, require explicit production refusal, and keep all credentials outside committed source files.
- [Resetting tables can destroy local data] → Require an explicit `--fresh` flag, print the target environment/database name without secrets, and never run reset implicitly during normal server startup.

## Migration Plan

1. Install Composer dependencies and copy the documented example environment file to a local `.env`.
2. Configure a dedicated MySQL/MariaDB database and run the migration command or `bin/serve --fresh` to create the tracked schema.
3. Run `bin/serve --seed` in a non-production environment to create the evaluator administrator and empty collections.
4. Start the development server, complete the login smoke test, and run PHPUnit/PHPStan.
5. For rollback before later phases exist, stop the server and remove the Phase 1 application tables through the explicit reset path; no existing production data is migrated by this change.

## Open Questions

- Which exact YAML parser package best fits the final Composer dependency set while remaining friendly to PHP 8.2 shared hosting?
- Should the production deployment documentation recommend a dedicated database user with migration-only setup privileges, or is the normal application user sufficient for Phase 1?
- After the route-matcher evaluation, is the custom matcher adequate for the anticipated public route patterns in Phase 3, or should FastRoute be adopted before then? (Phase 1 answer recorded above: the custom matcher is retained. The Phase 3 question stays open until the public route patterns are known.)
