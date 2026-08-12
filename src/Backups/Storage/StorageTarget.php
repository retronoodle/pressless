<?php

declare(strict_types=1);

namespace Stead\Backups\Storage;

/**
 * Storage-target interface for backup archives.
 *
 * Two implementations:
 *
 *   - {@see LocalStorageTarget} writes to a configured filesystem path.
 *   - {@see S3StorageTarget} uploads to an S3-compatible remote bucket.
 *
 * The interface is intentionally tiny (PUT/GET/DELETE/EXISTS/LIST) to
 * keep the S3 signing surface minimal, per the design.md risk note on
 * hand-rolled signing.
 */
interface StorageTarget
{
    public function name(): string;

    /**
     * Writes `$localPath` to the target under `$remoteKey`.
     */
    public function put(string $remoteKey, string $localPath): void;

    /**
     * Streams the remote object at `$remoteKey` to `$localPath`.
     * Returns the byte count written.
     */
    public function get(string $remoteKey, string $localPath): int;

    public function delete(string $remoteKey): void;

    public function exists(string $remoteKey): bool;

    /**
     * @return list<string> All remote keys currently on this target.
     */
    public function list(): array;
}
