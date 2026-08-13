<?php

declare(strict_types=1);

namespace Stead\Themes;

use Stead\Config\Configuration;

/**
 * Resolves the on-disk directory of the active theme.
 *
 * Prefers the database's active theme when that directory exists, then
 * falls back to `theme.active` in configuration. Database errors and a
 * missing folder never become a server error — they just skip to the
 * next candidate or return null.
 */
final class ActiveThemeResolver
{
    public function __construct(
        private readonly ThemeRepository $themes,
        private readonly Configuration $config,
    ) {
    }

    public function resolveThemeDirectory(): ?string
    {
        try {
            $active = $this->themes->findActive();
            if ($active !== null) {
                $directory = $this->directoryForSlug($active->slug);
                if ($directory !== null) {
                    return $directory;
                }
            }
        } catch (\Throwable) {
            // Fall through to the configured theme.
        }

        $fallback = $this->config->getString('theme.active');
        if ($fallback === '') {
            return null;
        }

        return $this->directoryForSlug($fallback);
    }

    private function directoryForSlug(string $slug): ?string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return null;
        }
        $root = $this->themesRoot();
        if ($root === null) {
            return null;
        }
        $candidate = $root . '/' . $slug;
        return is_dir($candidate) ? $candidate : null;
    }

    private function themesRoot(): ?string
    {
        $relative = $this->config->getString('paths.theme');
        if ($relative === '') {
            return null;
        }
        return rtrim($this->config->projectRoot(), '/') . '/' . trim($relative, '/');
    }
}
