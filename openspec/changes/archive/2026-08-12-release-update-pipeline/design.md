## Context

Stead ships to non-technical end users as a dist ZIP, never as a `git clone` (PRD §4, §8). Today there is no build step that produces that ZIP and no mechanism for an installed site to learn a newer version exists. `bin/serve` and `bin/migrate` (symfony/console commands) are the existing CLI pattern to follow for a new `bin/release` command. The web installer (Phase 10, already implemented) writes `installed.lock` as a build/install-time marker — the version file introduced here follows that same "flat marker file at repo root" convention.

There is no website release/download endpoint yet (it lives outside this repo). This design treats it as an external contract: a versioned JSON endpoint returning the latest tag and a download URL. Building that endpoint is out of scope here.

## Goals / Non-Goals

**Goals:**
- A repeatable, scriptable way to produce a clean, versioned, production-only dist ZIP from the repo.
- A CI pipeline that builds and publishes that ZIP automatically on tag push, so releases aren't a manual local step.
- An installed site can detect it's behind the latest release and tell an admin, without auto-applying anything.

**Non-Goals:**
- One-click / auto-apply updates (explicitly deferred per PRD §11 open question — v1 is manual download+extract instructions only).
- Building the website's release/download endpoint itself — this change assumes its contract, doesn't implement it.
- Signing/checksum verification of the ZIP (worth a follow-up, not blocking v1).

## Decisions

- **Version source of truth: a `VERSION` file at repo root, stamped at build time.** Simpler than parsing git describe/tags at runtime on the installed site (which has no `.git` directory once unzipped). The build script writes it from the tag being built; the update checker and admin UI just read a flat file. Alternative considered: storing version in `composer.json` — rejected because `composer.json` ships as-is in the ZIP and mixing "package metadata" with "this exact build's version" invites drift.
- **`bin/release` as a new symfony/console command**, consistent with `bin/serve`/`bin/migrate`. It shells out to `composer install --no-dev --optimize-autoloader` into a temp build directory, copies the repo minus dev/test paths (tests/, .git, openspec/, docs/ dev-only bits, phpunit/phpstan configs), writes `VERSION`, and zips.
- **CI: GitHub Actions workflow triggered on `v*.*.*` tag push.** Runs `bin/release`, then uploads/publishes the resulting ZIP to the website's release endpoint via an authenticated HTTP POST (endpoint URL + token from repo secrets). Chosen over a separate release tool because the repo already has no other CI infra to integrate with — a single workflow file is the smallest addition.
- **Update checker is a scheduled-on-admin-page-load check (with a cache), not a background daemon.** Stead has no long-running process/worker in this phase — checks piggyback on an admin request (e.g. dashboard load), cached for N hours in existing settings storage, to avoid hammering the release endpoint on every page view. Avoids adding a cron/queue dependency this early.
- **Update state cached in the existing settings mechanism, not a new table.** Only two values needed (last-checked timestamp, last-known-latest-version) — doesn't warrant new schema.

## Risks / Trade-offs

- [Release endpoint is unavailable/unbuilt when this ships] → Update checker fails closed (treats "can't reach endpoint" as "no update available", logs the failure) rather than blocking admin UI or erroring loudly to the end user.
- [Dist ZIP accidentally includes dev/test files or secrets like `.env`] → Build script uses an explicit include-list/exclude-list (not a blanket copy) and the smoke test in tasks.md verifies the ZIP contents directly.
- [CI publish step needs credentials to the website] → Stored as repo secrets; document required secret names in the workflow file itself so a maintainer can rotate them without reverse-engineering the script.
- [Manual update flow (v1) is friction for end users] → Accepted trade-off per PRD §11; one-click apply is an explicit future phase, not silently scoped in here.

## Migration Plan

Additive only — no existing runtime behavior changes, no DB migration. Rollout is: merge → tag a release → confirm CI produces and publishes the ZIP → confirm a test install's admin dashboard shows the update prompt correctly. No rollback concerns beyond reverting the workflow/command if the pipeline misbehaves.

## Open Questions

- Exact shape of the website's release/download endpoint (response schema, auth) — needs to be nailed down with whoever owns the website repo before `tasks.md` implementation of the CI publish step and the update checker's HTTP call.
- Where admin-visible update checks should live in the nav/dashboard — deferred to Phase 14 (admin UX polish) for final placement polish; this phase just needs it visible somewhere reasonable (e.g. dashboard or settings).
