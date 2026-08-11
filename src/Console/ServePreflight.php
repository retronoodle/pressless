<?php

declare(strict_types=1);

namespace Pressless\Console;

use Pressless\Config\Configuration;
use Pressless\Database\Connection;
use Pressless\Database\Migrator;
use Pressless\Exception\SafeException;
use Psr\Log\LoggerInterface;

/**
 * The pre-flight actions `bin/serve` runs before spawning `php -S`.
 *
 * Split from {@see ServeCommand} so the validation, fresh-reset, and seed
 * steps can be exercised in tests without starting a long-running server
 * process.
 */
final class ServePreflight
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array{host: string, port: int} $server
     * @return array{migrations: array{applied: list<string>, skipped: list<string>}, seed: array{admin_email: ?string, admin_password: ?string, collections_created: int}|null}
     */
    public function run(bool $fresh, bool $seed, array $server, bool $allowProductionSeed = false): array
    {
        if ($server['port'] <= 0 || $server['port'] > 65535) {
            throw new SafeException(
                sprintf('Invalid port "%d".', $server['port']),
                ['port' => $server['port']],
            );
        }
        if ($server['host'] === '') {
            throw new SafeException('Server host is not configured.');
        }

        // Database connectivity is validated up front: better to fail loudly
        // than to start a server that 500s on the first request.
        try {
            $this->connection->pdo();
        } catch (\Throwable $e) {
            $this->logger?->error('serve: database connection failed', ['error' => $e->getMessage()]);
            throw new SafeException('Could not connect to the configured database.', [], $e);
        }

        if ($fresh) {
            (new Migrator($this->connection, $this->config))->reset();
        }

        $migrationResult = (new Migrator($this->connection, $this->config))->migrate();

        $seedReport = null;
        if ($seed) {
            if ($this->config->isProduction() && !$allowProductionSeed) {
                throw new SafeException(
                    'Refusing to seed a production database. Re-run with --allow-production-seed to override.',
                    ['environment' => $this->config->environment()],
                );
            }
            $seedReport = (new Seeder($this->connection, $this->config))->seed($allowProductionSeed);
        }

        return [
            'migrations' => $migrationResult,
            'seed' => $seedReport,
        ];
    }
}