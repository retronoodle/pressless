## Why

Pressless needs a dependable runtime and persistence baseline before content, themes, and media can be built. Phase 1 establishes the smallest complete evaluator path—clone the repository, start a local server, initialize the database, and log into a quiet empty admin—so later phases can build on stable contracts instead of scaffolding ad hoc infrastructure.

## What Changes

- Initialize the PHP 8.2+ application with Composer metadata, PSR-4 autoloading, bootstrap flow, and the initial directory layout.
- Add environment and YAML configuration loading with safe defaults and clear database settings.
- Add a thin PDO database layer and versioned migration runner for MySQL/MariaDB, with SQLite supported as an additional driver for local evaluation.
- Create the initial schema for users, sessions, collections, entries, entry values, media, and revisions.
- Add front-controller request handling and a thin router for admin routes.
- Add native session authentication with bcrypt password hashing and a Twig login flow.
- Add the initial calm admin shell with navigation placeholders and empty states.
- Add `bin/serve`, including `--fresh` database recreation and `--seed` sample-data options.
- Add sample seed data containing an administrator and empty collections.
- Add an install-to-login smoke test covering the evaluator path.

## Capabilities

### New Capabilities

- `application-bootstrap`: Composer project metadata, PSR-4 loading, bootstrap lifecycle, and the initial application layout.
- `configuration`: Environment-variable and YAML configuration loading, validation, and runtime access.
- `database-foundations`: PDO connection/query abstraction, migration tracking, and the Phase 1 relational schema.
- `http-routing`: Front-controller request handling and thin route matching for the initial admin surface.
- `authentication`: Session-backed login, logout, password hashing, and protected-request behavior.
- `admin-shell`: Twig-rendered login and authenticated admin layout with navigation placeholders and empty states.
- `development-server`: The `bin/serve` evaluator command, fresh database reset, deterministic sample seeding, and smoke-test workflow.

### Modified Capabilities

<!-- No existing capability specs are present; all Phase 1 contracts are new. -->

## Impact

- Adds the PHP application source tree, Composer dependencies, CLI entry points, Twig templates, styles, migrations, seed data, and automated tests.
- Establishes database tables and repository-facing contracts used by subsequent content, media, revision, and permissions phases.
- Introduces local runtime configuration and database connection requirements for MySQL/MariaDB or SQLite.
- Defines the initial HTTP, authentication, and admin boundaries that future phases must preserve.
