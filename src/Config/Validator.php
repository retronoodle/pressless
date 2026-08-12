<?php

declare(strict_types=1);

namespace Stead\Config;

use Stead\Exception\SafeException;

final class Validator
{
    private const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];
    private const SUPPORTED_ENVIRONMENTS = ['production', 'development', 'test'];
    private const SUPPORTED_SAMESITE = ['Lax', 'Strict', 'None'];

    public static function validate(Configuration $config): void
    {
        self::validateDriver($config);
        self::validateEnvironment($config);
        self::validateDatabaseConnection($config);
        self::validatePaths($config);
        self::validateSessions($config);
        self::validateUpdateChecker($config);
    }

    private static function validateDriver(Configuration $config): void
    {
        $driver = strtolower($config->getString('database.connection'));
        if ($driver === '') {
            throw new SafeException(
                'Database driver is not configured. Set database.connection to mysql, mariadb, or sqlite.',
                ['setting' => 'database.connection'],
            );
        }
        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new SafeException(
                sprintf(
                    'Unsupported database driver "%s". Supported drivers: %s.',
                    $driver,
                    implode(', ', self::SUPPORTED_DRIVERS),
                ),
                ['setting' => 'database.connection', 'driver' => $driver],
            );
        }
    }

    private static function validateEnvironment(Configuration $config): void
    {
        $env = $config->environment();
        if (!in_array($env, self::SUPPORTED_ENVIRONMENTS, true)) {
            throw new SafeException(
                sprintf('Unsupported application environment "%s".', $env),
                ['setting' => 'app.environment', 'environment' => $env],
            );
        }
    }

    private static function validateDatabaseConnection(Configuration $config): void
    {
        $driver = strtolower($config->getString('database.connection'));

        if ($driver === 'sqlite') {
            $database = $config->getString('database.database');
            if ($database === '') {
                throw new SafeException(
                    'SQLite database path is not configured. Set database.database to a file path or :memory:.',
                    ['setting' => 'database.database'],
                );
            }
            if ($database !== ':memory:') {
                $dir = dirname($config->path('database.database'));
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new SafeException(
                        'SQLite database directory is not writable.',
                        ['setting' => 'database.database', 'directory' => $dir],
                    );
                }
            }
            return;
        }

        $required = [
            'database.host' => 'host',
            'database.database' => 'database name',
            'database.username' => 'username',
        ];
        foreach ($required as $key => $label) {
            $value = $config->getString($key);
            if ($value === '') {
                throw new SafeException(
                    sprintf('Required database setting "%s" (%s) is missing.', $key, $label),
                    ['setting' => $key],
                );
            }
        }

        $port = $config->getInt('database.port', 3306);
        if ($port <= 0 || $port > 65535) {
            throw new SafeException(
                'Database port must be between 1 and 65535.',
                ['setting' => 'database.port', 'port' => $port],
            );
        }
    }

    private static function validatePaths(Configuration $config): void
    {
        foreach (['paths.cache', 'paths.log'] as $key) {
            try {
                $path = $config->path($key);
            } catch (\Throwable $e) {
                throw new SafeException(
                    sprintf('Configured path "%s" could not be resolved.', $key),
                    ['setting' => $key],
                    $e,
                );
            }
            $dir = is_file($path) ? dirname($path) : $path;
            if (file_exists($dir) && !is_writable($dir)) {
                throw new SafeException(
                    sprintf('Configured path "%s" is not writable.', $key),
                    ['setting' => $key, 'directory' => $dir],
                );
            }
        }
    }

    private static function validateSessions(Configuration $config): void
    {
        $name = $config->getString('sessions.name');
        if ($name === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new SafeException(
                'Session cookie name must contain only letters, digits, underscores, and dashes.',
                ['setting' => 'sessions.name'],
            );
        }

        $samesite = $config->getString('sessions.cookie_samesite', 'Lax');
        if (!in_array($samesite, self::SUPPORTED_SAMESITE, true)) {
            throw new SafeException(
                sprintf(
                    'Unsupported session SameSite policy "%s". Supported: %s.',
                    $samesite,
                    implode(', ', self::SUPPORTED_SAMESITE),
                ),
                ['setting' => 'sessions.cookie_samesite'],
            );
        }

        $lifetime = $config->getInt('sessions.lifetime', 7200);
        if ($lifetime <= 0) {
            throw new SafeException(
                'Session lifetime must be a positive number of seconds.',
                ['setting' => 'sessions.lifetime'],
            );
        }
    }

    /**
     * The update checker settings are optional — an empty endpoint URL
     * disables update checks entirely. When set, the values still need to
     * be sane: the endpoint must look like an http(s) URL, the re-check
     * interval must be positive, and the timeout must be within a
     * reasonable range so a misconfigured operator can't accidentally
     * hang their admin dashboard.
     */
    private static function validateUpdateChecker(Configuration $config): void
    {
        $endpoint = $config->getString('update.endpoint_url');
        if ($endpoint !== '') {
            if (!preg_match('#^https?://[^\s]+$#i', $endpoint)) {
                throw new SafeException(
                    'Update endpoint URL must start with http:// or https:// and be a full URL.',
                    ['setting' => 'update.endpoint_url', 'value' => $endpoint],
                );
            }
        }

        $interval = $config->getInt('update.check_interval_hours', 24);
        if ($interval <= 0) {
            throw new SafeException(
                'Update check interval must be a positive number of hours.',
                ['setting' => 'update.check_interval_hours'],
            );
        }

        $timeout = $config->getInt('update.timeout_seconds', 5);
        if ($timeout <= 0 || $timeout > 60) {
            throw new SafeException(
                'Update check timeout must be between 1 and 60 seconds.',
                ['setting' => 'update.timeout_seconds'],
            );
        }
    }
}
