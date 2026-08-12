<?php

declare(strict_types=1);

namespace Stead\Backups;

use Stead\Exception\SafeException;

/**
 * Minimal record of a backup row read from the `backups` table.
 *
 * Mutable on `status`, `size_bytes`, and `error_message` so the
 * repository can update the row in place as a run progresses.
 */
final class Backup
{
    /**
     * @param array{db_driver: string, media_root: string, app_version: string} $context
     */
    public function __construct(
        private readonly int $id,
        private readonly string $target,
        private readonly string $storageKey,
        private int $sizeBytes,
        private string $status,
        private readonly string $triggeredBy,
        private ?string $errorMessage,
        private readonly string $createdAt,
        private readonly array $context,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function triggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @return array{db_driver: string, media_root: string, app_version: string}
     */
    public function context(): array
    {
        return $this->context;
    }

    public function markSuccess(int $sizeBytes): void
    {
        $this->status = BackupStatus::SUCCESS;
        $this->sizeBytes = $sizeBytes;
        $this->errorMessage = null;
    }

    public function markFailure(string $message): void
    {
        $this->status = BackupStatus::FAILED;
        $this->errorMessage = $message;
    }

    public function assertSucceeded(): void
    {
        if ($this->status !== BackupStatus::SUCCESS) {
            throw new SafeException(sprintf(
                'Backup #%d is not in a successful state (status=%s).',
                $this->id,
                $this->status,
            ));
        }
    }
}
