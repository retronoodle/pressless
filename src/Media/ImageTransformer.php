<?php

declare(strict_types=1);

namespace Stead\Media;

/**
 * Generates a resized image variant from a source file.
 *
 * Implementations operate on the filesystem and return the absolute path of
 * the generated file. They are responsible for producing a normalized
 * output format (JPEG by default) and are not concerned with caching — the
 * caller is responsible for only invoking them when the variant is missing.
 */
interface ImageTransformer
{
    /**
     * Resizes the source image to fit the given named size and writes the
     * result to the supplied destination path. Returns the destination
     * path on success.
     *
     * Implementations MUST validate that the source mime is one of the
     * supported image types and MUST throw on read/decoding failures.
     */
    public function transform(string $sourcePath, string $destPath, int $maxWidth, int $maxHeight): string;
}
