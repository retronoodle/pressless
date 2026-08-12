<?php

declare(strict_types=1);

namespace Stead\Update;

/**
 * Fetches the latest published release version from the website's release
 * endpoint.
 *
 * The endpoint contract (documented in config/app.yaml under `update`):
 *
 *   GET <endpoint_url>
 *   200 OK
 *   Content-Type: application/json
 *
 *   {
 *     "latest": "<version>",
 *     "url": "<download-url>"
 *   }
 *
 * Anything else — non-2xx, non-JSON, missing fields, malformed version —
 * is reported as a null result. The caller (UpdateChecker) is responsible
 * for treating null results as a fail-closed "no update" outcome; this
 * client deliberately does not throw, because the admin UI must never
 * surface a release-endpoint transport error to the user.
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
    public function __construct(
        private readonly string $endpointUrl,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function endpointUrl(): string
    {
        return $this->endpointUrl;
    }

    /**
     * @return array{latest: string, url: string}|null
     */
    public function fetchLatest(): ?array
    {
        if ($this->endpointUrl === '') {
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->endpointUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => max(1, min($this->timeoutSeconds, 5)),
                CURLOPT_USERAGENT => 'Stead-UpdateChecker/1.0',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
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

            $latest = $decoded['latest'] ?? null;
            $url = $decoded['url'] ?? null;
            if (!is_string($latest) || $latest === '' || !is_string($url) || $url === '') {
                return null;
            }

            return ['latest' => $latest, 'url' => $url];
        } finally {
            curl_close($ch);
        }
    }
}
