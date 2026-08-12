<?php

declare(strict_types=1);

namespace Stead\Media;

use Stead\Exception\SafeException;

/**
 * On-demand image transform generator backed by the filesystem cache under
 * `storage/media/{id}/{size}.jpg`.
 *
 * The set of allowed sizes is fixed at construction time. Unknown sizes
 * are rejected before any filesystem work happens so an attacker cannot
 * turn the transform endpoint into an arbitrary-dimension resize service.
 * Non-image mime types are rejected with the same defense.
 */
final class TransformCache
{
    /**
     * The named sizes exposed to themes. The keys are the size identifiers
     * that appear in URLs (`/media/{id}/thumb`); the values are the max
     * bounding box the resized image will fit inside.
     *
     * @var array<string, array{width: int, height: int}>
     */
    public const DEFAULT_SIZES = [
        'thumb' => ['width' => 200, 'height' => 200],
        'medium' => ['width' => 800, 'height' => 800],
        'full' => ['width' => 1600, 'height' => 1600],
    ];

    public const DEFAULT_EXTENSION = 'jpg';

    /** @var array<string, array{width: int, height: int}> */
    private array $sizes;

    /**
     * @param array<string, array{width: int, height: int}>|null $sizes
     */
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly ImageTransformer $transformer,
        ?array $sizes = null,
    ) {
        $this->sizes = $sizes ?? self::DEFAULT_SIZES;
    }

    /**
     * Returns the absolute path of the cached variant. Generates the
     * variant on first call and writes it to the disk cache.
     */
    public function ensureVariant(Media $media, string $size): string
    {
        if (!$media->isImage()) {
            throw new SafeException('Only image media can be transformed.');
        }

        if (!isset($this->sizes[$size])) {
            throw new SafeException(sprintf('Unknown transform size "%s".', $size));
        }

        $destPath = $this->storage->absoluteTransformPath($media->id(), $size, self::DEFAULT_EXTENSION);
        if (is_file($destPath)) {
            return $destPath;
        }

        $this->storage->ensureTransformDirectory($media->id());
        $sourcePath = $this->storage->absolutePath($media->path());

        $dimensions = $this->sizes[$size];
        return $this->transformer->transform(
            $sourcePath,
            $destPath,
            $dimensions['width'],
            $dimensions['height'],
        );
    }

    /**
     * @return array<string, array{width: int, height: int}>
     */
    public function sizes(): array
    {
        return $this->sizes;
    }

    public function hasSize(string $size): bool
    {
        return isset($this->sizes[$size]);
    }
}
