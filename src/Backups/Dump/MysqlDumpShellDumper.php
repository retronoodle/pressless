<?php

declare(strict_types=1);

namespace Stead\Backups\Dump;

use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Shells out to the system `mysqldump` binary.
 *
 * `mysqldump` produces portable, restorable SQL with minimal code and
 * is present on effectively every cPanel/shared-hosting PHP environment
 * Stead targets. We deliberately don't bundle a PHP equivalent as the
 * primary path because the shell tool is battle-tested on data shapes
 * the PDO fallback would have to discover manually.
 */
final class MysqlDumpShellDumper implements Dumper
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    public function dumpTo(string $outputPath): int
    {
        $factory = new DumperFactory($this->connection, $this->config);
        $binary = $factory->findMysqldump();
        if ($binary === null) {
            throw new SafeException('mysqldump binary not found.');
        }

        $host = $this->config->getString('database.host');
        $port = $this->config->getInt('database.port', 3306);
        $username = $this->config->getString('database.username');
        $database = $this->config->getString('database.database');
        $charset = $this->config->getString('database.charset', 'utf8mb4');

        $command = sprintf(
            '%s --host=%s --port=%d --user=%s --default-character-set=%s '
            . '--single-transaction --quick --routines --triggers %s > %s 2> %s',
            escapeshellcmd($binary),
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($charset),
            escapeshellarg($database),
            escapeshellarg($outputPath),
            escapeshellarg($outputPath . '.err'),
        );

        $exitCode = 0;
        $output = [];
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $stderr = @file_get_contents($outputPath . '.err');
            @unlink($outputPath . '.err');
            throw new SafeException(sprintf(
                'mysqldump failed (exit %d): %s',
                $exitCode,
                is_string($stderr) ? trim($stderr) : 'unknown error',
            ));
        }
        @unlink($outputPath . '.err');

        $size = @filesize($outputPath);
        if ($size === false) {
            throw new SafeException('mysqldump produced an unreadable output file.');
        }
        return $size;
    }
}
