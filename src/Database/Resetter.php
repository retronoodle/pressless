<?php

declare(strict_types=1);

namespace Stead\Database;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;

final class Resetter
{
    /** @var list<string> */
    private const TABLES_IN_DROP_ORDER = [
        'invites',
        'revisions',
        'entry_values',
        'entries',
        'media',
        'schema_change_log',
        'collections',
        'sessions',
        'users',
        'login_attempts',
        'mail_settings',
        'permissions',
        'roles',
        'backups',
        'redirects',
        'settings',
        'migrations',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    public function reset(): void
    {
        if (!$this->config->isDevelopment() && !$this->config->getBool('app.allow_reset', false)) {
            throw new SafeException(
                'Database reset is only allowed in development environments.',
                ['environment' => $this->config->environment()],
            );
        }

        $this->connection->transaction(function (Connection $conn) {
            $conn->pdo()->exec('PRAGMA foreign_keys = OFF');
            foreach (self::TABLES_IN_DROP_ORDER as $table) {
                $conn->pdo()->exec('DROP TABLE IF EXISTS ' . $conn->quote($table));
            }
            $conn->pdo()->exec('PRAGMA foreign_keys = ON');
        });
    }
}
