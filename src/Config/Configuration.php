<?php

declare(strict_types=1);

namespace Stead\Config;

use Stead\Support\ProjectRoot;
use Symfony\Component\Yaml\Yaml;

final class Configuration
{
    /** @var array<string, mixed> */
    private array $values;
    private readonly string $projectRoot;
    private readonly string $environment;

    /**
     * Maps UPPER_SNAKE_CASE env keys to nested config keys.
     * Anything not in this map is treated as a top-level lowercase key.
     * @var array<string, string>
     */
    private const ENV_MAP = [
        'APP_ENV' => 'app.environment',
        'APP_DEBUG' => 'app.debug',
        'APP_KEY' => 'app.key',
        'APP_URL' => 'app.url',
        'DB_CONNECTION' => 'database.connection',
        'DB_HOST' => 'database.host',
        'DB_PORT' => 'database.port',
        'DB_DATABASE' => 'database.database',
        'DB_USERNAME' => 'database.username',
        'DB_PASSWORD' => 'database.password',
        'DB_CHARSET' => 'database.charset',
        'DB_COLLATION' => 'database.collation',
        'DB_PREFIX' => 'database.prefix',
        'PATHS_MIGRATIONS' => 'paths.migrations',
        'PATHS_SEED' => 'paths.seed',
        'PATHS_TEMPLATES' => 'paths.templates',
        'PATHS_THEME' => 'paths.theme',
        'PATHS_CACHE' => 'paths.cache',
        'PATHS_LOG' => 'paths.log',
        'SESSION_NAME' => 'sessions.name',
        'SESSION_LIFETIME' => 'sessions.lifetime',
        'SESSION_COOKIE_SECURE' => 'sessions.cookie_secure',
        'SESSION_COOKIE_HTTPONLY' => 'sessions.cookie_httponly',
        'SESSION_COOKIE_SAMESITE' => 'sessions.cookie_samesite',
        'LOGGING_LEVEL' => 'logging.level',
        'LOGGING_FILE' => 'logging.file',
        'MAIL_FROM_EMAIL' => 'mail.from_email',
        'MAIL_FROM_NAME' => 'mail.from_name',
        'SERVE_HOST' => 'serve.host',
        'SERVE_PORT' => 'serve.port',
        'UPDATE_ENDPOINT_URL' => 'update.endpoint_url',
        'UPDATE_CHECK_INTERVAL_HOURS' => 'update.check_interval_hours',
        'UPDATE_TIMEOUT_SECONDS' => 'update.timeout_seconds',
        'BACKUPS_TARGET' => 'backups.target',
        'BACKUPS_LOCAL_PATH' => 'backups.local_path',
        'BACKUPS_S3_ENDPOINT' => 'backups.s3.endpoint',
        'BACKUPS_S3_BUCKET' => 'backups.s3.bucket',
        'BACKUPS_S3_REGION' => 'backups.s3.region',
        'BACKUPS_S3_KEY' => 'backups.s3.key',
        'BACKUPS_S3_SECRET' => 'backups.s3.secret',
        'BACKUPS_S3_ADDRESSING_STYLE' => 'backups.s3.addressing_style',
        'BACKUPS_RETENTION_COUNT' => 'backups.retention_count',
        'BACKUPS_FREQUENCY_HOURS' => 'backups.frequency_hours',
    ];

    /**
     * @param array<string, mixed>|null $values
     */
    public function __construct(string $projectRoot, string $environment = 'production', ?array $values = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->environment = $environment;

        if ($values !== null) {
            $this->values = $values;
        } else {
            $configPath = $this->projectRoot . '/config/app.yaml';
            $yaml = is_file($configPath) ? Yaml::parseFile($configPath) : [];
            $this->values = is_array($yaml) ? $yaml : [];
        }
    }

    public static function fromProjectRoot(string $projectRoot, string $environment): self
    {
        $envPath = $projectRoot . '/.env';
        $dotenvValues = Dotenv::load($envPath);

        $configPath = $projectRoot . '/config/app.yaml';
        $yaml = is_file($configPath) ? Yaml::parseFile($configPath) : [];
        $yaml = is_array($yaml) ? $yaml : [];

        $defaults = is_array($yaml['defaults'] ?? null) ? $yaml['defaults'] : [];
        $envConfig = is_array($yaml[$environment] ?? null) ? $yaml[$environment] : [];

        $merged = array_replace_recursive($defaults, $envConfig);

        foreach ($dotenvValues as $key => $value) {
            $mapped = self::ENV_MAP[$key] ?? strtolower($key);
            self::setNested($merged, $mapped, self::coerce($value));
        }

        $merged['app'] ??= [];
        $merged['app']['environment'] = $environment;
        $merged['app']['project_root'] = $projectRoot;

        return new self($projectRoot, $environment, $merged);
    }

    private static function coerce(string $value): mixed
    {
        if ($value === '') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if ($value === 'null') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return (float) $value;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function setNested(array &$arr, string $key, mixed $value): void
    {
        if (!str_contains($key, '.')) {
            $arr[$key] = $value;
            return;
        }
        $parts = explode('.', $key);
        $cursor = &$arr;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $cursor[$part] = $value;
                return;
            }
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!str_contains($key, '.')) {
            return $this->values[$key] ?? $default;
        }

        $parts = explode('.', $key);
        $cursor = $this->values;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'true' || $value === '1' || $value === 1) {
            return true;
        }
        if ($value === 'false' || $value === '0' || $value === 0) {
            return false;
        }
        return $default;
    }

    public function path(string $keyOrRelative, bool $mustExist = false): string
    {
        if (str_starts_with($keyOrRelative, 'paths.')) {
            $relative = $this->getString($keyOrRelative);
            if ($relative === '') {
                throw new \RuntimeException(sprintf('Path config key "%s" is empty.', $keyOrRelative));
            }
        } else {
            $relative = $keyOrRelative;
        }
        $absolute = $this->projectRoot . '/' . ltrim($relative, '/');
        $resolved = realpath($absolute);
        if ($resolved !== false) {
            $absolute = $resolved;
        }
        if ($mustExist && !file_exists($absolute)) {
            throw new \RuntimeException(sprintf('Required path does not exist: %s', $relative));
        }
        return $absolute;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function isDevelopment(): bool
    {
        return $this->environment !== 'production';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
