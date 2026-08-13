<?php

declare(strict_types=1);

namespace Stead\Themes;

/**
 * Reads and parses a theme's on-disk `theme.json` manifest.
 *
 * Used both during install (where the manifest still sits inside a ZIP
 * archive, parsed via {@see parseManifestJson}) and per request, where
 * the admin controller and Twig global re-read it from the active
 * theme's directory. The manifest is the single source of truth for the
 * settings schema — keys, types, labels, options — so this class never
 * touches the database; only values are persisted.
 */
final class ThemeManifestReader
{
    /** @var list<string> */
    public const SETTING_TYPES = ['text', 'textarea', 'boolean', 'select', 'color', 'image'];

    /**
     * Reads `theme.json` from `$themeDir`, returning the parsed manifest or
     * `null` when the file is missing or empty. Mirrors the soft-failure
     * behaviour of {@see ThemeInstaller::install()}: a missing manifest
     * isn't an error, callers fall back to slug-derived metadata.
     *
     * @return array{name: string, version: string, author: string, settings: list<array{key: string, label: string, type: string, default: string, options: list<string>}>}|null
     */
    public function readFrom(string $themeDir): ?array
    {
        $path = rtrim($themeDir, '/') . '/theme.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        return $this->parseManifestJson($raw, $themeDir);
    }

    /**
     * Parses a raw manifest JSON string. Used by {@see ThemeInstaller}
     * after reading the manifest out of an uploaded ZIP, and by
     * {@see readFrom()} for on-disk reads. Returns a normalised array
     * with a `settings` list — entries missing `key`/`type` or with an
     * unsupported `type` are dropped silently so a malformed manifest
     * never fails the call site.
     *
     * @return array{name: string, version: string, author: string, settings: list<array{key: string, label: string, type: string, default: string, options: list<string>}>}
     */
    public function parseManifestJson(string $raw, string $slugForName = ''): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->emptyManifest($slugForName);
        }

        $name = $decoded['name'] ?? null;
        $version = $decoded['version'] ?? null;
        $author = $decoded['author'] ?? null;

        if (!is_string($name) || $name === '') {
            $name = $this->nameFromSlug($slugForName);
        }
        if (!is_string($version)) {
            $version = '';
        }
        if (!is_string($author)) {
            $author = '';
        }

        return [
            'name' => $name,
            'version' => $version,
            'author' => $author,
            'settings' => $this->parseSettings($decoded['settings'] ?? null),
        ];
    }

    /**
     * @return list<array{key: string, label: string, type: string, default: string, options: list<string>}>
     */
    private function parseSettings(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $entry['key'] ?? null;
            $type = $entry['type'] ?? null;
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!is_string($type) || !in_array($type, self::SETTING_TYPES, true)) {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $label = $entry['label'] ?? null;
            $default = $entry['default'] ?? null;
            $options = $entry['options'] ?? null;

            $out[] = [
                'key' => $key,
                'label' => is_string($label) ? $label : ucwords(str_replace('_', ' ', $key)),
                'type' => $type,
                'default' => is_string($default) ? $default : '',
                'options' => $this->parseOptions($options, $type),
            ];
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function parseOptions(mixed $raw, string $type): array
    {
        if ($type !== 'select') {
            return [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $option) {
            if (is_string($option) && $option !== '') {
                $out[] = $option;
            }
        }
        return $out;
    }

    /**
     * @return array{name: string, version: string, author: string, settings: list<array{key: string, label: string, type: string, default: string, options: list<string>}>}
     */
    private function emptyManifest(string $slug): array
    {
        return [
            'name' => $this->nameFromSlug($slug),
            'version' => '',
            'author' => '',
            'settings' => [],
        ];
    }

    private function nameFromSlug(string $slug): string
    {
        $basename = basename(trim($slug, '/'));
        if ($basename === '') {
            return '';
        }
        return ucwords(str_replace('-', ' ', $basename));
    }
}
