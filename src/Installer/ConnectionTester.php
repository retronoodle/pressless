<?php

declare(strict_types=1);

namespace Stead\Installer;

use Stead\Config\Configuration;
use Stead\Config\Validator;
use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Builds an in-memory {@see Configuration} from installer form input and
 * validates that a real connection can be opened against it.
 *
 * The wizard has to test credentials *before* anything is written to disk, so
 * it cannot rely on `.env` existing yet. This class takes the four pieces of
 * information that are not safe to default and constructs a Configuration that
 * can be passed to {@see Validator::validate()} and {@see Connection::pdo()}.
 */
final class ConnectionTester
{
    /** @var list<string> */
    private const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /**
     * @param array{driver: string, host: string, port: int, database: string, username: string, password: string} $input
     */
    public function buildConfiguration(string $projectRoot, array $input): Configuration
    {
        $driver = strtolower(trim($input['driver']));
        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new SafeException(
                'Choose a supported database driver.',
                ['setting' => 'database.connection'],
            );
        }

        $host = trim($input['host']);
        $database = trim($input['database']);
        $username = trim($input['username']);
        $password = $input['password'];
        $port = $input['port'];
        if ($driver !== 'sqlite' && ($port < 1 || $port > 65535)) {
            throw new SafeException('Database port must be between 1 and 65535.', ['setting' => 'database.port']);
        }

        $values = [
            'app' => ['environment' => 'production'],
            'database' => [
                'connection' => $driver,
                'host' => $host !== '' ? $host : '127.0.0.1',
                'port' => (int) $port,
                'database' => $database,
                'username' => $username !== '' ? $username : 'root',
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'paths' => [
                'migrations' => 'database/migrations',
                'seed' => 'database/seed',
                'templates' => 'templates',
                'cache' => 'var/cache',
                'log' => 'var/log',
                'storage' => 'storage/media',
                'theme' => 'themes',
            ],
            'theme' => ['active' => ''],
            'sessions' => ['name' => 'stead_session', 'lifetime' => 7200, 'cookie_secure' => false, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax'],
            'logging' => ['level' => 'info', 'file' => 'stead.log'],
        ];

        $config = new Configuration($projectRoot, 'production', $values);

        Validator::validate($config);

        return $config;
    }

    /**
     * Attempts to open a connection and run a trivial query. Throws on failure
     * with the safe message produced by the {@see Connection} layer; the
     * installer controller is responsible for translating that into a user-
     * facing form error that omits the submitted password.
     */
    public function test(Configuration $config): void
    {
        $connection = new Connection($config);
        $connection->pdo();
        $connection->fetchOne('SELECT 1');
        $connection->close();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{driver: string, host: string, port: int, database: string, username: string, password: string}
     */
    public function normalize(array $input): array
    {
        $portRaw = $input['port'] ?? 3306;
        if (is_string($portRaw) && !preg_match('/^-?\d+$/', $portRaw)) {
            $portRaw = 3306;
        }
        return [
            'driver' => strtolower(trim((string) ($input['driver'] ?? 'mysql'))),
            'host' => trim((string) ($input['host'] ?? '127.0.0.1')),
            'port' => (int) $portRaw,
            'database' => trim((string) ($input['database'] ?? '')),
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
        ];
    }
}