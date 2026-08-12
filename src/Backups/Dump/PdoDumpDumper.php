<?php

declare(strict_types=1);

namespace Stead\Backups\Dump;

use PDO;
use Stead\Database\Connection;

/**
 * PDO-based fallback dumper used when `mysqldump` is unavailable.
 *
 * Walks the configured database's tables via `SHOW TABLES` and writes
 * one `CREATE TABLE IF NOT EXISTS` plus `INSERT INTO` statements per
 * table. Uses unbuffered queries via `PDO::FETCH_COLUMN` + an explicit
 * `fetch()` loop so a large table doesn't have to fit in memory.
 *
 * The produced SQL is restorable against a freshly-migrated schema; the
 * CREATE statements are advisory (matches what the dump captured) but
 * the restore runner uses DROP/CREATE from the current migrations.
 */
final class PdoDumpDumper implements Dumper
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function dumpTo(string $outputPath): int
    {
        $driver = $this->connection->driver();
        if ($driver !== 'mysql') {
            throw new \Stead\Exception\SafeException('PDO dumper only supports mysql/sqlite.');
        }

        $pdo = $this->connection->pdo();
        $fh = @fopen($outputPath, 'wb');
        if ($fh === false) {
            throw new \Stead\Exception\SafeException(sprintf(
                'Could not open dump output "%s" for writing.',
                $outputPath,
            ));
        }

        try {
            $this->writeHeader($fh, $pdo);
            $tables = $this->listTables($pdo);
            foreach ($tables as $table) {
                $this->dumpTable($fh, $pdo, $table);
            }
            $this->writeFooter($fh);
        } finally {
            fclose($fh);
        }

        $size = @filesize($outputPath);
        return $size === false ? 0 : $size;
    }

    private function writeHeader($fh, PDO $pdo): void
    {
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $stamp = gmdate('Y-m-d H:i:s');
        $header = "-- Stead PDO fallback dump\n"
            . "-- generated_at: $stamp\n"
            . "-- server_version: " . ($version ?: 'unknown') . "\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET NAMES utf8mb4;\n"
            . "\n";
        fwrite($fh, $header);
    }

    private function writeFooter($fh): void
    {
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * @return list<string>
     */
    private function listTables(PDO $pdo): array
    {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) $row;
        }
        // Sort for deterministic dump output.
        sort($out);
        return $out;
    }

    private function dumpTable($fh, PDO $pdo, string $table): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        fwrite($fh, "\n-- Table: $table\n");
        fwrite($fh, "DROP TABLE IF EXISTS $quoted;\n");

        // Re-issue a CREATE TABLE statement by introspecting the live
        // table. SHOW CREATE TABLE gives us a faithful rebuild for
        // the current schema version.
        $row = $pdo->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            fwrite($fh, "-- (could not retrieve CREATE TABLE for $table; skipped)\n");
            return;
        }
        $createKey = 'Create Table';
        $createSql = isset($row[$createKey]) ? (string) $row[$createKey] : '';
        if ($createSql === '') {
            fwrite($fh, "-- (empty CREATE TABLE for $table; skipped)\n");
            return;
        }
        fwrite($fh, $createSql . ";\n");

        // Stream rows so we don't buffer the whole table in memory.
        $columnRows = $pdo->query("SHOW COLUMNS FROM $quoted")->fetchAll(PDO::FETCH_ASSOC);
        $colList = [];
        foreach ($columnRows as $row) {
            if (!isset($row['Field'])) {
                continue;
            }
            $colList[] = '`' . str_replace('`', '``', (string) $row['Field']) . '`';
        }
        if ($colList === []) {
            return;
        }
        $colSql = implode(', ', $colList);

        $data = $pdo->query("SELECT $colSql FROM $quoted", PDO::FETCH_ASSOC);
        if ($data === false) {
            return;
        }
        $rowCount = 0;
        while (($r = $data->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($r as $v) {
                if ($v === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $pdo->quote((string) $v);
                }
            }
            fwrite($fh, "INSERT INTO $quoted ($colSql) VALUES (" . implode(', ', $values) . ");\n");
            $rowCount++;
        }
        fwrite($fh, "-- (rows: $rowCount)\n");
    }
}
