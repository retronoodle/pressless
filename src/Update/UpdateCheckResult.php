<?php

declare(strict_types=1);

namespace Stead\Update;

/**
 * The outcome of one (possibly cached) update check.
 *
 * The shape is deliberately narrow: only the two pieces of information the
 * admin UI needs. Internal details (cache file location, raw endpoint
 * response) stay behind the checker so the UI doesn't grow coupling to them.
 */
final class UpdateCheckResult
{
    public function __construct(
        public readonly string $installedVersion,
        public readonly ?string $latestVersion,
        public readonly ?string $downloadUrl,
        public readonly bool $isUpToDate,
        public readonly ?string $error,
        public readonly bool $fromCache,
        public readonly int $checkedAt,
        public readonly ?string $installedVersionReleasedAt = null,
    ) {
    }

    public function hasUpdate(): bool
    {
        return !$this->isUpToDate && $this->latestVersion !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'installed' => $this->installedVersion,
            'latest' => $this->latestVersion,
            'download_url' => $this->downloadUrl,
            'is_up_to_date' => $this->isUpToDate,
            'has_update' => $this->hasUpdate(),
            'error' => $this->error,
            'from_cache' => $this->fromCache,
            'checked_at' => $this->checkedAt,
        ];
        if ($this->installedVersionReleasedAt !== null) {
            $out['installed_version_released_at'] = $this->installedVersionReleasedAt;
        }
        return $out;
    }
}
