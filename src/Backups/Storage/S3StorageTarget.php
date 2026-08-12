<?php

declare(strict_types=1);

namespace Stead\Backups\Storage;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;

/**
 * Minimal S3-compatible storage target.
 *
 * Implements signed PUT/GET/DELETE/HEAD/LIST against an S3-compatible
 * endpoint using AWS Signature V4. Designed for shared-hosting PHP
 * environments where `shell_exec` may be disabled but `cURL` is
 * available — the signing math is hand-rolled, ~200 lines, and covered
 * by tests against a local HTTP test double.
 *
 * Supports both virtual-host and path-style addressing, configured
 * via `backups.s3.addressing_style`:
 *
 *   - path:    https://{endpoint}/{bucket}/{key}
 *   - virtual: https://{bucket}.{endpoint}/{key}
 *
 * No multipart upload — backups are written as a single PUT object.
 */
final class S3StorageTarget implements StorageTarget
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const SERVICE = 's3';

    public function __construct(
        private readonly Configuration $config,
        private readonly ?\CurlHandle $curl = null,
    ) {
    }

    public function name(): string
    {
        return 's3';
    }

    public function put(string $remoteKey, string $localPath): void
    {
        $body = @file_get_contents($localPath);
        if ($body === false) {
            throw new SafeException(sprintf('Could not read local file "%s" for upload.', $localPath));
        }
        $this->signedRequest('PUT', $remoteKey, $body, 'application/octet-stream', [
            'Content-Length' => (string) strlen($body),
        ]);
    }

    public function get(string $remoteKey, string $localPath): int
    {
        $response = $this->signedRequest('GET', $remoteKey);
        if (!isset($response['body'])) {
            throw new SafeException('S3 GET returned an empty body.');
        }
        $bytes = file_put_contents($localPath, $response['body']);
        if ($bytes === false) {
            throw new SafeException(sprintf('Could not write S3 object to "%s".', $localPath));
        }
        return $bytes;
    }

    public function delete(string $remoteKey): void
    {
        $this->signedRequest('DELETE', $remoteKey);
    }

    public function exists(string $remoteKey): bool
    {
        $response = $this->signedRequest('HEAD', $remoteKey);
        return ($response['status'] ?? 0) === 200;
    }

    public function list(): array
    {
        $response = $this->signedRequest('GET', '', '', '', [], query: 'list-type=2');
        $body = $response['body'] ?? '';
        $out = [];
        // Parse S3 ListObjectsV2 XML response with a simple regex —
        // we only need the <Key> entries, not the full schema.
        if (is_string($body) && $body !== '') {
            if (preg_match_all('#<Key>([^<]+)</Key>#', $body, $matches)) {
                foreach ($matches[1] as $key) {
                    $out[] = (string) $key;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function signedRequest(
        string $method,
        string $key,
        string $body = '',
        string $contentType = '',
        array $extraHeaders = [],
        string $query = '',
    ): array {
        $endpoint = $this->config->getString('backups.s3.endpoint');
        $bucket = $this->config->getString('backups.s3.bucket');
        $region = $this->config->getString('backups.s3.region', 'us-east-1');
        $accessKey = $this->config->getString('backups.s3.key');
        $secretKey = $this->config->getString('backups.s3.secret');
        $style = $this->config->getString('backups.s3.addressing_style', 'path');

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new SafeException(
                'S3 backup target is not fully configured. Set BACKUPS_S3_ENDPOINT, '
                . 'BACKUPS_S3_BUCKET, BACKUPS_S3_KEY, and BACKUPS_S3_SECRET.',
            );
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $endpointHost = preg_replace('#^https?://#', '', $endpoint);
        $endpointHost = rtrim($endpointHost, '/');

        if ($style === 'virtual') {
            $host = $bucket . '.' . $endpointHost;
            $canonicalUri = '/' . ltrim($key, '/');
        } else {
            $host = $endpointHost;
            $canonicalUri = '/' . $bucket . ($key !== '' ? '/' . ltrim($key, '/') : '');
        }

        $canonicalQuery = $query;

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', $body),
            'x-amz-date' => $amzDate,
        ];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        foreach ($extraHeaders as $h => $v) {
            $headers[strtolower($h)] = $v;
        }
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $h => $v) {
            $canonicalHeaders .= $h . ':' . trim($v) . "\n";
            $signedHeaders .= $h . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . hash('sha256', $body);

        $credentialScope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $region, self::SERVICE);
        $stringToSign = self::ALGORITHM . "\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac(
            'sha256',
            'aws4_request',
            hash_hmac(
                'sha256',
                self::SERVICE,
                hash_hmac('sha256', $region, hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true), true),
                true,
            ),
            true,
        );
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $accessKey,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        $url = 'https://' . $host . $canonicalUri;
        if ($canonicalQuery !== '') {
            $url .= '?' . $canonicalQuery;
        }

        $headers['Authorization'] = $authorization;

        return $this->curlRequest($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function curlRequest(string $method, string $url, array $headers, string $body): array
    {
        $ch = $this->curl ?? curl_init();
        $this->curl = $ch;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            throw new SafeException(sprintf('S3 request failed: %s', $error));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseHeaders = $this->parseHeaders(substr((string) $raw, 0, $headerSize));
        $responseBody = (string) substr((string) $raw, $headerSize);

        if ($status >= 400) {
            throw new SafeException(sprintf(
                'S3 request returned HTTP %d: %s',
                $status,
                substr($responseBody, 0, 500),
            ));
        }
        return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))] = trim($value);
        }
        return $out;
    }
}
