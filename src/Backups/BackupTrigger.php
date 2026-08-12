<?php

declare(strict_types=1);

namespace Stead\Backups;

/**
 * Origin of a backup run. `pre_update` is recorded for backups triggered
 * by the update-instructions flow, so the admin history can show why each
 * backup exists.
 */
final class BackupTrigger
{
    public const MANUAL = 'manual';
    public const SCHEDULED = 'scheduled';
    public const PRE_UPDATE = 'pre_update';
}
