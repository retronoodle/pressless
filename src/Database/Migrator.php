<?php

declare(strict_types=1);

namespace Stead\Database;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;

final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @return list<array{version: string, file: string}>
     */
    public function discover(): array
    {
        $dir = $this->config->path('paths.migrations');
        if (!is_dir($dir)) {
            return [];
        }
        $driver = $this->connection->driver();
        $suffix = $driver === 'mysql' ? '.mysql.sql' : '.sqlite.sql';
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, $suffix)) {
                continue;
            }
            $version = substr($entry, 0, -strlen($suffix));
            $files[$version] = $dir . '/' . $entry;
        }
        ksort($files);
        $result = [];
        foreach ($files as $version => $path) {
            $result[] = [
                'version' => $version,
                'file' => $path,
            ];
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        $this->ensureMigrationsTable();
        $rows = $this->connection->fetchAll('SELECT version FROM migrations ORDER BY version ASC');
        $versions = [];
        foreach ($rows as $r) {
            $versions[] = (string) $r['version'];
        }
        return $versions;
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $applied = array_flip($this->applied());
        $pending = [];
        foreach ($this->discover() as $migration) {
            if (!isset($applied[$migration['version']])) {
                $pending[] = $migration['version'];
            }
        }
        return $pending;
    }

    /**
     * @return array{applied: list<string>, skipped: list<string>}
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $appliedVersions = $this->applied();
        $applied = array_flip($appliedVersions);
        $newlyApplied = [];
        $skipped = [];

        foreach ($this->discover() as $migration) {
            $version = $migration['version'];
            if (isset($applied[$version])) {
                $skipped[] = $version;
                continue;
            }

            $sql = file_get_contents($migration['file']);
            if ($sql === false) {
                throw new SafeException(sprintf('Could not read migration "%s".', $migration['file']));
            }

            $this->connection->transaction(function (Connection $conn) use ($sql, $version) {
                $this->runStatements($conn, $sql);
                $conn->execute(
                    'INSERT INTO migrations (version, applied_at) VALUES (:version, :applied_at)',
                    ['version' => $version, 'applied_at' => gmdate('Y-m-d H:i:s')],
                );
            });

            $newlyApplied[] = $version;
        }

        return ['applied' => $newlyApplied, 'skipped' => $skipped];
    }

    public function reset(): void
    {
        $resetter = new Resetter($this->connection, $this->config);
        $resetter->reset();
    }

    private function ensureMigrationsTable(): void
    {
        $driver = $this->connection->driver();
        $q = $this->connection->quote('version');
        $applied = $this->connection->quote('applied_at');
        if ($driver === 'mysql') {
            $this->connection->execute(
                "CREATE TABLE IF NOT EXISTS migrations (
                    version VARCHAR(64) PRIMARY KEY,
                    applied_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            $this->connection->execute(
                "CREATE TABLE IF NOT EXISTS migrations (
                    version TEXT PRIMARY KEY,
                    applied_at TEXT NOT NULL
                )"
            );
        }
    }

    private function runStatements(Connection $conn, string $sql): void
    {
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*(?:\n|$)/', $sql) ?: []),
            static fn(string $s) => $s !== '',
        );
        foreach ($statements as $statement) {
            $conn->pdo()->exec($statement);
        }
    }
}
