## 1. Release build

- [x] 1.1 Build `bin/release` symfony/console command: installs prod-only composer deps into a temp build dir, excludes dev/test paths (`tests/`, `.git/`, `openspec/`, `phpunit.xml`, `phpstan.neon`, `.env`), writes a `VERSION` file with the given version, and zips the result
- [x] 1.2 Add an explicit include/exclude list (not a blanket copy) so dist contents are deterministic and auditable

## 2. Tagged-release CI pipeline

- [x] 2.1 Add a CI workflow triggered on `vX.Y.Z` tag push that runs `bin/release`
- [x] 2.2 Publish the built ZIP to the website's release/download endpoint (authenticated request; document required secrets in the workflow file)
- [x] 2.3 Confirm non-release branch/tag pushes do not trigger the publish step

## 3. Update checker

- [x] 3.1 Implement installed-version read from the `VERSION` file
- [x] 3.2 Implement a client for the website's release endpoint that fetches the latest published version
- [x] 3.3 Implement fail-closed error handling (unreachable/error → treated as no update, logged, no admin-facing error)
- [x] 3.4 Implement caching of the check result (last-checked timestamp + last-known version) in existing settings storage, with a configurable re-check interval

## 4. Admin update notifications

- [x] 4.1 Build the update-available notice in the admin UI, sourced from the cached update-checker result
- [x] 4.2 Build the manual update instructions view (download + extract over existing install)

## 5. Verification

- [x] 5.1 Smoke test: tag a release → confirm CI builds the ZIP and publishes it → confirm dist ZIP excludes dev/test files
- [x] 5.2 Smoke test: point a test install's update checker at a release endpoint reporting a newer version → confirm the admin notice appears with correct version and instructions
- [x] 5.3 Smoke test: simulate release-endpoint failure → confirm update checker fails closed and no error surfaces to the admin
