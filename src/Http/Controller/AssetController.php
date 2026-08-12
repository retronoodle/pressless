<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Config\Configuration;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves static files from the active theme's `assets/` directory.
 *
 * The route is mounted ahead of `/{collectionSlug}` so the fixed `assets`
 * prefix always wins. Path traversal is rejected by resolving the requested
 * path under the assets directory and confirming the real path stays inside
 * it; anything else returns the kernel's 404 path.
 *
 * No render cache is involved: filesystem reads are already cheap, and the
 * primary correctness concern is the traversal guard, not throughput.
 */
final class AssetController
{
    private const MAX_AGE = 86400;

    /** @var array<string, string> */
    private const CONTENT_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
    ];

    public function __construct(private readonly Configuration $config)
    {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function serve(Request $request, array $parameters = []): Response
    {
        $path = (string) ($parameters['path'] ?? '');
        if ($path === '') {
            return $this->notFound();
        }

        $assetsRoot = $this->resolveAssetsRoot();
        if ($assetsRoot === null) {
            return $this->notFound();
        }

        $candidate = $assetsRoot . '/' . $path;
        $real = realpath($candidate);
        if ($real === false) {
            return $this->notFound();
        }
        if (!str_starts_with($real, $assetsRoot . DIRECTORY_SEPARATOR)) {
            return $this->notFound();
        }
        if (!is_file($real)) {
            return $this->notFound();
        }

        $contentType = $this->contentTypeFor($real);
        $response = new BinaryFileResponse($real, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
        ]);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($real),
        );
        return $response;
    }

    private function resolveAssetsRoot(): ?string
    {
        $active = $this->config->getString('theme.active');
        if ($active === '') {
            return null;
        }
        $themeRoot = $this->config->getString('paths.theme');
        if ($themeRoot === '') {
            return null;
        }
        $candidate = rtrim($this->config->projectRoot(), '/')
            . '/' . trim($themeRoot, '/')
            . '/' . trim($active, '/')
            . '/assets';
        $real = realpath($candidate);
        return $real === false ? null : $real;
    }

    private function contentTypeFor(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND);
    }
}
