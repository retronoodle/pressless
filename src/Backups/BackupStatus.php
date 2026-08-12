<?php

declare(strict_types=1);

namespace Stead\Backups;

/**
 * Status values recorded on each `backups` row.
 */
final class BackupStatus
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
}
