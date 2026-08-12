<?php

declare(strict_types=1);

namespace Stead\Backups\Storage;

/**
 * String-constant convenience for callers that want a stable name
 * without importing the storage classes.
 */
final class BackupTargetLocal
{
    public const NAME = 'local';
}
