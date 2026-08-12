<?php

declare(strict_types=1);

namespace Stead\Backups\Storage;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;

/**
 * Builds the right storage target from configuration.
 *
 * Centralises the "which target?" decision so the bin/backup command,
 * the restore flow, and the admin UI all agree on the same answer.
 */
final class StorageTargetFactory
{
    public function __construct(private readonly Configuration $config)
    {
    }

    public function fromConfig(): StorageTarget
    {
        $target = strtolower($this->config->getString('backups.target', 'local'));
        return match ($target) {
            BackupTargetLocal::NAME => new LocalStorageTarget($this->config),
            BackupTargetS3::NAME => new S3StorageTarget($this->config),
            default => throw new SafeException(sprintf(
                'Unknown backup target "%s". Expected one of: local, s3.',
                $target,
            )),
        };
    }

    public function forTarget(string $name): StorageTarget
    {
        return match (strtolower($name)) {
            BackupTargetLocal::NAME => new LocalStorageTarget($this->config),
            BackupTargetS3::NAME => new S3StorageTarget($this->config),
            default => throw new SafeException(sprintf('Unknown backup target "%s".', $name)),
        };
    }
}
