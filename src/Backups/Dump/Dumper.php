<?php

declare(strict_types=1);

namespace Stead\Backups\Dump;

/**
 * Writes a database dump to the supplied file path.
 *
 * Implementations are responsible for producing a SQL stream that can be
 * replayed against the same driver version that produced it (see
 * {@see \Stead\Backups\Restore\RestoreRunner}).
 */
interface Dumper
{
    /**
     * Writes the full database dump to `$outputPath`.
     *
     * Returns the byte count written so callers can record it as the
     * "DB portion" size (the full archive also includes media, which is
     * added separately by the archive builder).
     */
    public function dumpTo(string $outputPath): int;
}
