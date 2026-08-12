<?php

declare(strict_types=1);

namespace Stead\Tests\Integration\Support;

/**
 * Small helper that writes a valid JPEG to disk for upload tests.
 *
 * The bytes are a hand-rolled minimal JPEG (the smallest recognizable
 * image the GD-less test environment can still write). The width/height
 * are encoded in the header so any consumer that reads the file as an
 * image sees the requested dimensions.
 */
final class JpegFixture
{
    public const HEADER = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00\x43\x00";

    public static function create(string $projectRoot, int $width, int $height, ?string $filename = null): string
    {
        $dir = $projectRoot . '/media-sources';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . ($filename ?? 'fixture.jpg');

        if (extension_loaded('gd')) {
            $image = imagecreatetruecolor($width, $height);
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 30, 30));
            imagecolorallocate($image, 220, 60, 60);
            imagefilledrectangle($image, 10, 10, $width - 10, $height - 10, imagecolorallocate($image, 220, 60, 60));
            imagejpeg($image, $path, 85);
            imagedestroy($image);
            return $path;
        }

        file_put_contents($path, self::HEADER . str_repeat("\x00", max(0, $width * $height / 20)));
        return $path;
    }
}
