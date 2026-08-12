<?php

declare(strict_types=1);

namespace Stead\Media;

/**
 * GD-backed {@see ImageTransformer}.
 *
 * Fits the source image inside the given max-width/max-height box,
 * preserving the aspect ratio. Output is always JPEG. The source image is
 * decoded by GD's imagecreatefrom* family and explicitly freed after the
 * resize.
 */
final class GdImageTransformer implements ImageTransformer
{
    public function transform(string $sourcePath, string $destPath, int $maxWidth, int $maxHeight): string
    {
        if (!is_file($sourcePath)) {
            throw new \Stead\Exception\SafeException(sprintf('Source image not found: "%s".', $sourcePath));
        }

        $source = $this->loadImage($sourcePath);
        if ($source === null) {
            throw new \Stead\Exception\SafeException(sprintf('Could not decode image: "%s".', $sourcePath));
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $dimensions = $this->fit($sourceWidth, $sourceHeight, $maxWidth, $maxHeight);
        $destination = imagecreatetruecolor($dimensions['width'], $dimensions['height']);
        if ($destination === false) {
            imagedestroy($source);
            throw new \Stead\Exception\SafeException('Could not allocate destination image.');
        }

        imagecopyresampled(
            $destination,
            $source,
            0,
            0,
            0,
            0,
            $dimensions['width'],
            $dimensions['height'],
            $sourceWidth,
            $sourceHeight,
        );

        $written = imagejpeg($destination, $destPath, 85);
        imagedestroy($source);
        imagedestroy($destination);

        if ($written === false) {
            throw new \Stead\Exception\SafeException(sprintf('Could not write resized image to "%s".', $destPath));
        }

        return $destPath;
    }

    /**
     * @return \GdImage|null
     */
    private function loadImage(string $path)
    {
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return null;
        }
        $type = (int) $info[2];
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => $this->loadPng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => null,
        };
    }

    /**
     * @return \GdImage|null
     */
    private function loadPng(string $path)
    {
        $image = @imagecreatefrompng($path);
        if ($image === false) {
            return null;
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return $image;
    }

    /**
     * Returns the largest width/height that fits inside the supplied max
     * box while preserving the source aspect ratio.
     *
     * @return array{width: int, height: int}
     */
    private function fit(int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight): array
    {
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return ['width' => max(1, $maxWidth), 'height' => max(1, $maxHeight)];
        }

        $widthRatio = $maxWidth / $sourceWidth;
        $heightRatio = $maxHeight / $sourceHeight;
        $ratio = min($widthRatio, $heightRatio, 1.0);

        $width = max(1, (int) round($sourceWidth * $ratio));
        $height = max(1, (int) round($sourceHeight * $ratio));
        return ['width' => $width, 'height' => $height];
    }
}
