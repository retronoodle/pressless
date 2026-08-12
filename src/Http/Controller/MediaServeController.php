<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Exception\SafeException;
use Stead\Media\LocalStorage;
use Stead\Media\MediaRepository;
use Stead\Media\TransformCache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves uploaded media files and their cached transforms.
 *
 * Routes are of the form `/media/{id}/{key}` where `{key}` is either:
 *   - `original` — return the file as uploaded
 *   - one of the size keys registered on {@see TransformCache::sizes()} —
 *     return the cached or freshly-generated resize variant
 *
 * Path traversal is rejected the same way {@see AssetController} does: the
 * resolved real path must stay inside the media storage root.
 */
final class MediaServeController
{
    private const MAX_AGE = 86400;

    public function __construct(
        private readonly MediaRepository $media,
        private readonly LocalStorage $storage,
        private readonly TransformCache $transforms,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function serve(Request $request, array $parameters = []): Response
    {
        $id = (int) ($parameters['id'] ?? 0);
        $key = (string) ($parameters['key'] ?? '');

        if ($id <= 0 || $key === '') {
            return $this->notFound();
        }

        $media = $this->media->find($id);
        if ($media === null) {
            return $this->notFound();
        }

        if ($key === 'original') {
            return $this->serveOriginal($media);
        }

        if (!$this->transforms->hasSize($key)) {
            return $this->notFound();
        }
        if (!$media->isImage()) {
            return $this->notFound();
        }

        try {
            $absolutePath = $this->transforms->ensureVariant($media, $key);
        } catch (SafeException $e) {
            return $this->notFound();
        }

        if (!is_file($absolutePath)) {
            return $this->notFound();
        }

        return $this->fileResponse($absolutePath, $this->contentTypeFor($absolutePath));
    }

    private function serveOriginal(\Stead\Media\Media $media): Response
    {
        $absolutePath = $this->storage->absolutePath($media->path());
        $real = realpath($absolutePath);
        if ($real === false || !is_file($real)) {
            return $this->notFound();
        }
        $root = $this->storage->root();
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return $this->notFound();
        }

        return $this->fileResponse($real, $media->mimeType());
    }

    private function fileResponse(string $path, string $contentType): Response
    {
        $response = new BinaryFileResponse($path, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
        ]);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($path),
        );
        return $response;
    }

    private function contentTypeFor(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND);
    }
}
