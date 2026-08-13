<?php

declare(strict_types=1);

namespace Stead\Settings;

/**
 * The single-row site-wide settings (site name, timezone, date format,
 * homepage type override).
 *
 * `homepageType` is `null` when no admin override is saved — meaning the
 * active theme's `theme.json` `homepage_type` (or `collection_list` as a
 * final fallback) wins. When `homepageType === 'static_page'`,
 * `homepagePageId` carries the entry id to render; otherwise it is
 * always `null` so clearing the override round-trips through a single,
 * unambiguous state.
 *
 * The form layer hands a stored value back to the renderer untouched so an
 * admin reloading the page after a validation failure sees what they typed.
 */
final class Settings
{
    public const DEFAULT_TIMEZONE = 'UTC';
    public const DEFAULT_DATE_FORMAT = 'Y-m-d';

    public const HOMEPAGE_TYPE_STATIC_PAGE = 'static_page';

    public function __construct(
        public readonly string $siteName,
        public readonly string $timezone,
        public readonly string $dateFormat,
        public readonly ?string $homepageType = null,
        public readonly ?int $homepagePageId = null,
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
            self::normaliseHomepageType($row['homepage_type'] ?? null),
            self::normaliseHomepagePageId($row['homepage_page_id'] ?? null, $row['homepage_type'] ?? null),
        );
    }

    private static function normaliseHomepageType(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        return $trimmed === self::HOMEPAGE_TYPE_STATIC_PAGE ? self::HOMEPAGE_TYPE_STATIC_PAGE : null;
    }

    private static function normaliseHomepagePageId(mixed $rawId, mixed $rawType): ?int
    {
        if (self::normaliseHomepageType($rawType) !== self::HOMEPAGE_TYPE_STATIC_PAGE) {
            return null;
        }
        if (!is_numeric($rawId)) {
            return null;
        }
        $id = (int) $rawId;
        return $id > 0 ? $id : null;
    }
}
