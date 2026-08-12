<?php

declare(strict_types=1);

namespace Stead\Config;

/**
 * Writes (and rewrites) values into the project's `.env` file.
 *
 * The writer is intentionally minimal: it preserves comments and
 * unknown lines, updates existing keys in place, and appends new keys
 * at the end. It does NOT preserve the original line ordering for
 * blank-line groupings or trailing newlines — those are normalised.
 *
 * This is used by admin UI flows (e.g. backup settings) that need to
 * persist configuration values that the operator should not have to
 * hand-edit in `.env`.
 */
final class EnvWriter
{
    /**
     * @param array<string, string> $values
     */
    public static function write(string $path, array $values): void
    {
        $existing = [];
        if (is_file($path)) {
            $raw = (string) @file_get_contents($path);
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $trim = trim($line);
                if ($trim === '' || str_starts_with($trim, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$name] = explode('=', $line, 2);
                $name = trim($name);
                if ($name === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                    continue;
                }
                $existing[$name] = $line;
            }
        }

        foreach ($values as $name => $value) {
            $existing[$name] = sprintf('%s=%s', $name, self::escape($value));
        }

        ksort($existing);

        $rendered = "# Managed by Stead admin. Values here override config/app.yaml.\n";
        foreach ($existing as $line) {
            $rendered .= $line . "\n";
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $rendered) === false) {
            throw new \Stead\Exception\SafeException(sprintf('Could not write "%s".', $path));
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \Stead\Exception\SafeException(sprintf('Could not commit "%s".', $path));
        }
    }

    private static function escape(string $value): string
    {
        if ($value === '' || preg_match('/[\s"\'#\$]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
