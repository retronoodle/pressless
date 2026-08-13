<?php

declare(strict_types=1);

namespace Stead\Update;

/**
 * Fetches GitHub release metadata (latest release, or a specific tag) for
 * a configured `owner/repo`.
 *
 * Two public methods share a single transport layer:
 *
 *   - {@see self::fetchLatest()}         → `GET .../releases/latest`
 *   - {@see self::fetchReleaseByTag()}   → `GET .../releases/tags/<tag>`
 *
 * The shared cURL/JSON plumbing lives in the private
 * {@see self::fetchJson()} helper, so both endpoints apply the same
 * timeout, user-agent, accept header, 2xx check, and JSON-decode rules.
 *
 * Latest-endpoint contract (documented in config/app.yaml under `update`):
 *
 *   GET https://api.github.com/repos/<owner>/<repo>/releases/latest
 *   Accept: application/vnd.github+json
 *   User-Agent: Stead-UpdateChecker/1.0
 *   200 OK
 *   Content-Type: application/json
 *
 *   {
 *     "tag_name": "v1.2.3",
 *     "published_at": "2026-08-13T12:34:56Z",
 *     "assets": [
 *       { "name": "stead-1.2.3.zip", "browser_download_url": "https://..." },
 *       ...
 *     ],
 *     "zipball_url": "https://api.github.com/repos/.../zipball/v1.2.3"
 *   }
 *
 * The tag-endpoint returns the same shape for the requested tag.
 *
 * Parsing rules (matched against the contract above):
 *
 *   - `latest` / `version` is taken from `tag_name`, stripped of any
 *     leading `v`.
 *   - `published_at` is captured verbatim as the ISO-8601 timestamp.
 *   - The download URL is taken from the first asset in `assets[]`
 *     whose `name` ends in `.zip` (via its `browser_download_url`). If
 *     no asset matches, we fall back to `zipball_url` so a release
 *     published without an attached ZIP still degrades gracefully.
 *     `fetchReleaseByTag()` may return `null` here on its own — the
 *     tag-lookup path tolerates a missing download URL — whereas
 *     `fetchLatest()` requires one (the dashboard banner has no use
 *     for a release it can't link to).
 *   - Anything else — non-2xx, non-JSON, missing `tag_name`, missing
 *     `published_at` (latest path), or no usable URL (latest path) —
 *     is reported as a null result. The caller (UpdateChecker) treats
 *     null as fail-closed "no update"; this client deliberately does
 *     not throw, because the admin UI must never surface a
 *     release-endpoint transport error.
 *
 * Unauthenticated requests are subject to GitHub's 60 req/hr/IP rate
 * limit; that's accepted because the caller caches each result for
 * `update.check_interval_hours` (default 24h) and the check only fires
 * on admin page loads, not per-request.
 *
 * Uses cURL directly rather than introducing an HTTP-client dependency;
 * the request shape is trivial and cURL is in every PHP runtime that
 * ships with this codebase's other extensions.
 *
 * Not declared `final` so test doubles can replace it without resorting
 * to reflection. The class is intentionally small and stable; production
 * code should compose it via {@see \Stead\Update\UpdateChecker::fromConfig()}
 * rather than instantiating it directly.
 */
class ReleaseEndpointClient
{
    public const DEFAULT_API_BASE_URL = 'https://api.github.com';

    public function __construct(
        private readonly string $githubRepo,
        private readonly int $timeoutSeconds,
        private readonly string $apiBaseUrl = self::DEFAULT_API_BASE_URL,
    ) {
    }

    public function githubRepo(): string
    {
        return $this->githubRepo;
    }

    /**
     * @return array{latest: string, url: string, published_at: string}|null
     */
    public function fetchLatest(): ?array
    {
        if ($this->githubRepo === '' || !str_contains($this->githubRepo, '/')) {
            return null;
        }

        $decoded = $this->fetchJson('releases/latest');
        if ($decoded === null) {
            return null;
        }

        $tag = $decoded['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '') {
            return null;
        }
        $latest = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
        if ($latest === '') {
            return null;
        }

        $publishedAt = $decoded['published_at'] ?? null;
        if (!is_string($publishedAt) || $publishedAt === '') {
            return null;
        }

        $downloadUrl = $this->selectDownloadUrl($decoded);
        if ($downloadUrl === null) {
            return null;
        }

        return ['latest' => $latest, 'url' => $downloadUrl, 'published_at' => $publishedAt];
    }

    /**
     * Fetches a single release by its GitHub tag. The `$tag` is expected
     * to be GitHub-tag-shaped (e.g. `v1.2.3`); this method does not add
     * or strip a `v` prefix — the caller is responsible for that.
     *
     * @return array{version: string, published_at: string, download_url: string|null}|null
     */
    public function fetchReleaseByTag(string $tag): ?array
    {
        if ($this->githubRepo === '' || !str_contains($this->githubRepo, '/')) {
            return null;
        }
        if ($tag === '') {
            return null;
        }

        $decoded = $this->fetchJson('releases/tags/' . $tag);
        if ($decoded === null) {
            return null;
        }

        $tagValue = $decoded['tag_name'] ?? null;
        if (!is_string($tagValue) || $tagValue === '') {
            return null;
        }
        $version = str_starts_with($tagValue, 'v') ? substr($tagValue, 1) : $tagValue;
        if ($version === '') {
            return null;
        }

        $publishedAt = $decoded['published_at'] ?? null;
        if (!is_string($publishedAt) || $publishedAt === '') {
            return null;
        }

        return [
            'version' => $version,
            'published_at' => $publishedAt,
            'download_url' => $this->selectDownloadUrl($decoded),
        ];
    }

    /**
     * Runs a cURL GET against the configured repo, expecting a 2xx JSON
     * response. Returns the decoded body, or `null` on any failure
     * (connect/timeout, non-2xx, malformed JSON). The two public methods
     * {@see self::fetchLatest()} and {@see self::fetchReleaseByTag()} are
     * thin parse layers on top of this helper.
     *
     * @return array<string, mixed>|null
     */
    private function fetchJson(string $path): ?array
    {
        $url = rtrim($this->apiBaseUrl, '/') . '/repos/' . $this->githubRepo . '/' . ltrim($path, '/');

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => max(1, min($this->timeoutSeconds, 5)),
                CURLOPT_USERAGENT => 'Stead-UpdateChecker/1.0',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                ],
            ]);

            $body = curl_exec($ch);
            if ($body === false) {
                return null;
            }
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (!is_int($status) || $status < 200 || $status >= 300) {
                return null;
            }

            $decoded = json_decode((string) $body, true);
            if (!is_array($decoded)) {
                return null;
            }

            return $decoded;
        } finally {
            curl_close($ch);
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function selectDownloadUrl(array $decoded): ?string
    {
        $assets = $decoded['assets'] ?? null;
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = $asset['name'] ?? null;
                if (!is_string($name) || !str_ends_with($name, '.zip')) {
                    continue;
                }
                $assetUrl = $asset['browser_download_url'] ?? null;
                if (is_string($assetUrl) && $assetUrl !== '') {
                    return $assetUrl;
                }
            }
        }

        $zipball = $decoded['zipball_url'] ?? null;
        if (is_string($zipball) && $zipball !== '') {
            return $zipball;
        }

        return null;
    }
}