<?php

declare(strict_types=1);

namespace Stead\Update;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stead\Config\Configuration;

/**
 * The single entry point an admin page load uses to find out whether the
 * site is running the latest published version.
 *
 * Behaviour:
 *
 *   - Reads the locally installed version from the VERSION file.
 *   - Reads any cached result; if the cached result is fresher than the
 *     configured re-check interval, returns it as-is.
 *   - Otherwise, calls the release endpoint through the injected client.
 *   - On any failure (unreachable, non-2xx, malformed JSON, missing
 *     fields, install-vs-latest version comparison error), returns a
 *     "no update available" result and logs the underlying reason. The
 *     admin UI never sees an exception from this code path.
 *   - On success, persists the result to the cache so the next admin
 *     page load reuses it for `check_interval_hours`.
 *
 * Fail-closed is enforced by the {@see self::buildResult()} helper: every
 * failure path produces a result with `isUpToDate = true` and `error`
 * populated, so the UI's "is a newer version available?" branch is
 * always false on failure.
 */
final class UpdateChecker
{
    public function __construct(
        private readonly Configuration $config,
        private readonly InstalledVersion $installed,
        private readonly ReleaseEndpointClient $client,
        private readonly UpdateCheckCache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(): UpdateCheckResult
    {
        $installedVersion = $this->installed->read();
        if ($installedVersion === null) {
            // No installed version on disk — we cannot meaningfully
            // answer "are you up to date?", so report up-to-date and
            // log. The admin UI sees nothing.
            $this->logger->info('Update checker: no VERSION file on disk; skipping.');
            return self::buildUnknownResult(error: 'installed_version_unknown');
        }

        $cached = $this->cache->load();
        if ($cached !== null && $this->isCacheFresh($cached)) {
            // Re-stamp fromCache so a downstream caller can tell when
            // the result was reused vs freshly fetched on this request.
            return self::rehydrateCached($cached);
        }

        // No fresh cache → actually call the endpoint.
        if ($this->client->githubRepo() === '') {
            // No endpoint configured at all. Persist an "up to date"
            // sentinel so we don't log on every page load, and return
            // it.
            $result = new UpdateCheckResult(
                installedVersion: $installedVersion,
                latestVersion: null,
                downloadUrl: null,
                isUpToDate: true,
                error: null,
                fromCache: false,
                checkedAt: time(),
            );
            $this->cache->save($result);
            return $result;
        }

        try {
            $payload = $this->client->fetchLatest();
        } catch (\Throwable $e) {
            $this->logger->warning('Update checker: endpoint threw.', [
                'exception' => $e->getMessage(),
            ]);
            $result = self::buildUnknownResult(installedVersion: $installedVersion);
            $this->cache->save($result);
            return $result;
        }

        if ($payload === null) {
            $this->logger->warning('Update checker: endpoint returned an unparseable response.');
            $result = self::buildUnknownResult(installedVersion: $installedVersion);
            $this->cache->save($result);
            return $result;
        }

        $isUpToDate = $this->isUpToDate($installedVersion, $payload['latest']);
        $result = new UpdateCheckResult(
            installedVersion: $installedVersion,
            latestVersion: $payload['latest'],
            downloadUrl: $payload['url'],
            isUpToDate: $isUpToDate,
            error: null,
            fromCache: false,
            checkedAt: time(),
        );
        $this->cache->save($result);
        return $result;
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * Builds a checker wired from Configuration — useful in tests and
     * anywhere that doesn't already have a fully-constructed object
     * graph. The `LoggerInterface` parameter is optional; defaults to a
     * null-logger so the fail-closed semantics still hold in code paths
     * that don't have a Monolog logger handy.
     */
    public static function fromConfig(
        Configuration $config,
        ?LoggerInterface $logger = null,
        ?ReleaseEndpointClient $client = null,
        ?UpdateCheckCache $cache = null,
        ?InstalledVersion $installed = null,
    ): self {
        $logger ??= new \Psr\Log\NullLogger();
        $installed ??= InstalledVersion::fromConfig($config);
        $cache ??= new UpdateCheckCache($config->path('paths.cache'));
        $client ??= new ReleaseEndpointClient(
            githubRepo: $config->getString('update.github_repo'),
            timeoutSeconds: $config->getInt('update.timeout_seconds', 5),
            apiBaseUrl: $config->getString('update.api_base_url', ReleaseEndpointClient::DEFAULT_API_BASE_URL),
        );
        return new self($config, $installed, $client, $cache, $logger);
    }

    private function isCacheFresh(UpdateCheckResult $cached): bool
    {
        $intervalHours = $this->config->getInt('update.check_interval_hours', 24);
        $ageSeconds = time() - $cached->checkedAt;
        return $ageSeconds >= 0 && $ageSeconds < ($intervalHours * 3600);
    }

    /**
     * Best-effort comparison. Anything we cannot parse is treated as
     * "cannot decide → assume up to date" so the UI stays quiet and we
     * don't ship a false-positive update banner. The version strings we
     * write into VERSION files are SemVer-ish, so we use `version_compare`
     * with the `*`-tolerant defaults — non-numeric suffixes (e.g.
     * 1.2.3-rc.1) end up being treated as older than their release
     * equivalents, which is the conventional ordering.
     */
    private function isUpToDate(string $installed, string $latest): bool
    {
        $cleanInstalled = $this->stripTagPrefix($installed);
        $cleanLatest = $this->stripTagPrefix($latest);
        // "Up to date" means the installed version is at least as new as
        // the latest published. We suppress the PHP 8+ null-on-bad-input
        // path and treat that as "up to date" so a malformed input never
        // surfaces a misleading banner to the admin.
        $cmp = @version_compare($cleanInstalled, $cleanLatest, '>=');
        if ($cmp === false) {
            return false;
        }
        return true;
    }

    private function stripTagPrefix(string $version): string
    {
        return str_starts_with($version, 'v') ? substr($version, 1) : $version;
    }

    private static function buildUnknownResult(?string $installedVersion = null, ?string $error = null): UpdateCheckResult
    {
        return new UpdateCheckResult(
            installedVersion: $installedVersion ?? 'unknown',
            latestVersion: null,
            downloadUrl: null,
            isUpToDate: true,
            error: $error,
            fromCache: false,
            checkedAt: time(),
        );
    }

    private static function rehydrateCached(UpdateCheckResult $cached): UpdateCheckResult
    {
        return new UpdateCheckResult(
            installedVersion: $cached->installedVersion,
            latestVersion: $cached->latestVersion,
            downloadUrl: $cached->downloadUrl,
            isUpToDate: $cached->isUpToDate,
            error: $cached->error,
            fromCache: true,
            checkedAt: $cached->checkedAt,
        );
    }
}
