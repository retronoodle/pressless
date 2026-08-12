<?php

declare(strict_types=1);

namespace Stead\Settings;

/**
 * The single-row site-wide settings (site name, timezone, date format).
 *
 * The form layer hands a stored value back to the renderer untouched so an
 * admin reloading the page after a validation failure sees what they typed.
 */
final class Settings
{
    public const DEFAULT_TIMEZONE = 'UTC';
    public const DEFAULT_DATE_FORMAT = 'Y-m-d';

    public function __construct(
        public readonly string $siteName,
        public readonly string $timezone,
        public readonly string $dateFormat,
    ) {
    }

    public static function defaults(): self
    {
        return new self('', self::DEFAULT_TIMEZONE, self::DEFAULT_DATE_FORMAT);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['site_name'] ?? ''),
            (string) ($row['timezone'] ?? self::DEFAULT_TIMEZONE),
            (string) ($row['date_format'] ?? self::DEFAULT_DATE_FORMAT),
        );
    }
}