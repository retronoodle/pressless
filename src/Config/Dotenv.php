<?php

declare(strict_types=1);

namespace Stead\Config;

final class Dotenv
{
    /**
     * @return array<string, string>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $line = preg_replace('/^export\s+/i', '', $line) ?? $line;
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$name, $rawValue] = explode('=', $line, 2);
                $name = trim($name);
                $rawValue = trim($rawValue);
                if ($name === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                    continue;
                }
                if (
                    (str_starts_with($rawValue, '"') && str_ends_with($rawValue, '"'))
                    || (str_starts_with($rawValue, "'") && str_ends_with($rawValue, "'"))
                ) {
                    $rawValue = substr($rawValue, 1, -1);
                }
                $values[$name] = $rawValue;
            }
        } finally {
            fclose($handle);
        }

        $applied = [];
        foreach ($values as $name => $value) {
            $existing = getenv($name);
            if ($existing === false || $existing === '') {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $applied[$name] = $value;
            } else {
                $applied[$name] = $existing;
            }
        }

        return $applied;
    }
}
