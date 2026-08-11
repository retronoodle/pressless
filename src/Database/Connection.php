<?php

declare(strict_types=1);

namespace Stead\Database;

use PDO;
use PDOException;
use PDOStatement;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;

final class Connection
{
    public const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    private ?PDO $pdo = null;

    public function __construct(private readonly Configuration $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }
        return $this->pdo;
    }

    public function driver(): string
    {
        $driver = strtolower($this->config->getString('database.connection'));
        if ($driver === 'mariadb') {
            return 'mysql';
        }
        return $driver;
    }

    public function quote(string $tableOrColumn): string
    {
        $driver = $this->driver();
        if ($driver === 'mysql') {
            return '`' . str_replace('`', '``', $tableOrColumn) . '`';
        }
        if ($driver === 'sqlite') {
            return '"' . str_replace('"', '""', $tableOrColumn) . '"';
        }
        throw new SafeException('Unknown driver for quoting.');
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            if ($stmt === false) {
                throw new SafeException('Failed to prepare database statement.');
            }
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new SafeException(
                'Database operation failed.',
                ['sql_state' => $e->getCode()],
                $e,
            );
        }
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function connect(): PDO
    {
        $driver = $this->config->getString('database.connection');
        if ($driver === '') {
            throw new SafeException('Database driver is not configured.');
        }

        if ($driver === 'sqlite') {
            return $this->connectSqlite();
        }

        return $this->connectMysql();
    }

    private function connectMysql(): PDO
    {
        $host = $this->config->getString('database.host');
        $port = $this->config->getInt('database.port', 3306);
        $database = $this->config->getString('database.database');
        $username = $this->config->getString('database.username');
        $password = $this->config->getString('database.password');
        $charset = $this->config->getString('database.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        return $this->openPdo($dsn, $username, $password);
    }

    private function connectSqlite(): PDO
    {
        $database = $this->config->getString('database.database');
        if ($database === '') {
            throw new SafeException('SQLite database path is not configured.');
        }
        if ($database !== ':memory:') {
            $absolute = $database;
            if (!str_starts_with($absolute, '/')) {
                $absolute = $this->config->projectRoot() . '/' . ltrim($absolute, '/');
            }
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new SafeException('Could not create SQLite database directory.');
                }
            }
            $database = $absolute;
        }
        $dsn = 'sqlite:' . $database;
        $pdo = $this->openPdo($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        return $pdo;
    }

    /**
     * @param array<int, int> $extraAttributes
     */
    private function openPdo(string $dsn, ?string $user, ?string $pass, array $extraAttributes = []): PDO
    {
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                ...$extraAttributes,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            throw new SafeException(
                'Could not connect to the database.',
                ['driver' => $this->config->getString('database.connection')],
                $e,
            );
        }
    }
}
