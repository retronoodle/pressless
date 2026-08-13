<?php

declare(strict_types=1);

namespace Stead\Update;

/**
 * Fetches the latest published release version from GitHub's Releases API
 * for a configured `owner/repo`.
 *
 * The endpoint contract (documented in config/app.yaml under `update`):
 *
 *   GET https://api.github.com/repos/<owner>/<repo>/releases/latest
 *   Accept: application/vnd.github+json
 *   User-Agent: Stead-UpdateChecker/1.0
 *   200 OK
 *   Content-Type: application/json
 *
 *   {
 *     "tag_name": "v1.2.3",
 *     "assets": [
 *       { "name": "stead-1.2.3.zip", "browser_download_url": "https://..." },
 *       ...
 *     ],
 *     "zipball_url": "https://api.github.com/repos/.../zipball/v1.2.3"
 *   }
 *
 * Parsing rules (matched against the contract above):
 *
 *   - `latest` is taken from `tag_name`, stripped of any leading `v`.
 *   - `url` is taken from the first asset in `assets[]` whose `name`
 *     ends in `.zip` (via its `browser_download_url`). If no asset
 *     matches, we fall back to `zipball_url` so a release published
 *     without an attached ZIP still degrades gracefully.
 *   - Anything else — non-2xx, non-JSON, missing `tag_name`, or no
 *     usable URL — is reported as a null result. The caller
 *     (UpdateChecker) treats null as fail-closed "no update"; this
 *     client deliberately does not throw, because the admin UI must
 *     never surface a release-endpoint transport error.
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
     * Builds the full API URL for the configured `owner/repo`. Exposed
     * (not `private`) only so tests can subclass and point at a local
     * fake server; production code should not override this.
     */
    protected function apiUrl(): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/repos/' . $this->githubRepo . '/releases/latest';
    }

    /**
     * @return array{latest: string, url: string}|null
     */
    public function fetchLatest(): ?array
    {
        if ($this->githubRepo === '' || !str_contains($this->githubRepo, '/')) {
            return null;
        }

        $url = $this->apiUrl();

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

            $tag = $decoded['tag_name'] ?? null;
            if (!is_string($tag) || $tag === '') {
                return null;
            }
            $latest = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
            if ($latest === '') {
                return null;
            }

            $downloadUrl = $this->selectDownloadUrl($decoded);
            if ($downloadUrl === null) {
                return null;
            }

            return ['latest' => $latest, 'url' => $downloadUrl];
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