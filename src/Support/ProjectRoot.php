<?php

declare(strict_types=1);

namespace Stead\Support;

final class ProjectRoot
{
    public static function resolve(?string $startPath = null): string
    {
        $startPath ??= $_SERVER['SCRIPT_FILENAME'] ?? __DIR__;
        $startPath = realpath($startPath) ?: $startPath;
        $dir = is_dir($startPath) ? $startPath : dirname($startPath);

        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Could not resolve the Stead project root.');
    }
}
