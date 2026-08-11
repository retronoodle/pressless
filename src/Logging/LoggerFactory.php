<?php

declare(strict_types=1);

namespace Pressless\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Pressless\Config\Configuration;
use Pressless\Exception\SafeException;

final class LoggerFactory
{
    public static function create(Configuration $config): Logger
    {
        $level = self::resolveLevel($config->getString('logging.level', 'info'));
        $logDir = $config->path('paths.log');
        $logFile = $logDir . '/' . $config->getString('logging.file', 'pressless.log');
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handler = new StreamHandler($logFile, $level);
        $formatter = new LineFormatter(
            "[%datetime%] %level_name% %channel% %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger = new Logger('pressless');
        $logger->pushHandler($handler);
        $logger->pushProcessor(self::redactSecrets(...));

        return $logger;
    }

    public static function redactSecrets(LogRecord $record): LogRecord
    {
        $sensitiveKeys = [
            'password', 'passwd', 'pwd',
            'secret', 'token', 'api_key', 'apikey',
            'session', 'cookie', 'authorization',
            'db_password', 'database_password',
        ];

        $redact = static function (mixed $value) use ($sensitiveKeys, &$redact): mixed {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_string($k) && self::isSensitiveKey($k, $sensitiveKeys)) {
                        $value[$k] = '[redacted]';
                    } else {
                        $value[$k] = $redact($v);
                    }
                }
                return $value;
            }
            if (is_string($value) && strlen($value) > 200) {
                return substr($value, 0, 200) . '...';
            }
            return $value;
        };

        return new LogRecord(
            datetime: $record->datetime,
            channel: $record->channel,
            level: $record->level,
            message: (string) $redact($record->message),
            context: $redact($record->context),
            extra: $redact($record->extra),
        );
    }

    /**
     * @param string[] $sensitiveKeys
     */
    private static function isSensitiveKey(string $key, array $sensitiveKeys): bool
    {
        $lower = strtolower($key);
        foreach ($sensitiveKeys as $candidate) {
            if (str_contains($lower, $candidate)) {
                return true;
            }
        }
        return false;
    }

    private static function resolveLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}
