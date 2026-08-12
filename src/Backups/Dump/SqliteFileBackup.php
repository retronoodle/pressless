<?php

declare(strict_types=1);

namespace Stead\Backups\Dump;

use PDO;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * SQLite backup path: emits a SQL dump via PDO so the restore path can
 * replay statements uniformly (the same code that replays mysqldump
 * and the PDO MySQL fallback).
 *
 * SQLite's `VACUUM INTO` is faster but produces a binary database copy
 * that would need a separate restore path. For v1, statement-replay
 * uniformity is more valuable than micro-optimising the dump step.
 */
final class SqliteFileBackup implements Dumper
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    public function dumpTo(string $outputPath): int
    {
        $fh = @fopen($outputPath, 'wb');
        if ($fh === false) {
            throw new SafeException(sprintf('Could not open SQLite dump "%s" for writing.', $outputPath));
        }

        try {
            $pdo = $this->connection->pdo();
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
        $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
        $stamp = gmdate('Y-m-d H:i:s');
        $header = "-- Stead SQLite dump\n"
            . "-- generated_at: $stamp\n"
            . "-- sqlite_version: " . ($version ?: 'unknown') . "\n"
            . "PRAGMA foreign_keys=OFF;\n"
            . "\n";
        fwrite($fh, $header);
    }

    private function writeFooter($fh): void
    {
        fwrite($fh, "\nPRAGMA foreign_keys=ON;\n");
    }

    /**
     * @return list<string>
     */
    private function listTables(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) $row;
        }
        return $out;
    }

    private function dumpTable($fh, PDO $pdo, string $table): void
    {
        // Safely quote the table name for use in SQL statements.
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        fwrite($fh, "\n-- Table: $table\n");
        fwrite($fh, "DROP TABLE IF EXISTS $quoted;\n");

        $createRow = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name = " . $pdo->quote($table),
        )->fetchColumn();
        if (is_string($createRow) && $createRow !== '') {
            fwrite($fh, $createRow . ";\n");
        }

        // Fetch column names via PRAGMA so we get them even when the
        // table is empty (LIMIT 0 on an empty table can return no
        // metadata depending on the driver).
        $columnRows = $pdo->query("PRAGMA table_info($quoted)")->fetchAll(PDO::FETCH_ASSOC);
        $colList = [];
        foreach ($columnRows as $row) {
            if (!isset($row['name'])) {
                continue;
            }
            $colList[] = '"' . str_replace('"', '""', (string) $row['name']) . '"';
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
