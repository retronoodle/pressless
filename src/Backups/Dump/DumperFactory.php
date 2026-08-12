<?php

declare(strict_types=1);

namespace Stead\Backups\Dump;

use Stead\Config\Configuration;
use Stead\Database\Connection;

/**
 * Produces a SQL dump of the configured database suitable for replay
 * during a restore.
 *
 * Two implementations exist:
 *
 *   - {@see MysqlDumpShellDumper}: shells out to `mysqldump` when the
 *     binary is available on the host.
 *   - {@see PdoDumpDumper}: a PHP-only fallback that walks the schema
 *     via PDO and writes equivalent INSERT statements.
 *
 * SQLite doesn't need a dumper at all — see {@see SqliteFileBackup}.
 *
 * The factory picks an implementation based on the configured driver
 * and the presence of `mysqldump` on the host's PATH.
 */
final class DumperFactory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    public function pick(): Dumper
    {
        $driver = $this->connection->driver();
        if ($driver === 'sqlite') {
            return new SqliteFileBackup($this->connection, $this->config);
        }
        if ($driver !== 'mysql') {
            throw new \Stead\Exception\SafeException(sprintf(
                'No backup dumper available for database driver "%s".',
                $driver,
            ));
        }

        if ($this->findMysqldump() !== null) {
            return new MysqlDumpShellDumper($this->connection, $this->config);
        }
        return new PdoDumpDumper($this->connection);
    }

    public function findMysqldump(): ?string
    {
        // We deliberately shell out via `command -v` rather than rely
        // on a fixed path, since shared-host binaries live in many
        // places. If `shell_exec` is disabled (some hardened hosts)
        // this returns null and the caller falls back to PDO.
        if (\function_exists('shell_exec')) {
            $which = @shell_exec('command -v mysqldump 2>/dev/null');
            if (is_string($which)) {
                $which = trim($which);
                if ($which !== '') {
                    return $which;
                }
            }
        }
        return null;
    }

    public function getDriver(): string
    {
        return $this->connection->driver();
    }
}
