<?php

declare(strict_types=1);

namespace Stead\Settings;

use Stead\Database\Connection;

/**
 * Loads and persists the single-row site settings.
 *
 * The seeded row (id=1) is upserted in place — there is only ever one
 * settings record. {@see load()} returns sane defaults when no row
 * exists yet so callers never have to special-case the empty case.
 */
final class SettingsRepository
{
    private const ROW_ID = 1;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(): Settings
    {
        $row = $this->connection->fetchOne(
            'SELECT site_name, timezone, date_format FROM settings WHERE id = :id',
            ['id' => self::ROW_ID],
        );
        if ($row === null) {
            return Settings::defaults();
        }
        return Settings::fromRow($row);
    }

    public function save(Settings $settings): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = $this->connection->fetchOne(
            'SELECT id FROM settings WHERE id = :id',
            ['id' => self::ROW_ID],
        );
        if ($row === null) {
            $this->connection->execute(
                'INSERT INTO settings (id, site_name, timezone, date_format, created_at, updated_at)
                 VALUES (:id, :site_name, :timezone, :date_format, :created_at, :updated_at)',
                [
                    'id' => self::ROW_ID,
                    'site_name' => $settings->siteName,
                    'timezone' => $settings->timezone,
                    'date_format' => $settings->dateFormat,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            return;
        }
        $this->connection->execute(
            'UPDATE settings
                SET site_name = :site_name,
                    timezone = :timezone,
                    date_format = :date_format,
                    updated_at = :updated_at
              WHERE id = :id',
            [
                'site_name' => $settings->siteName,
                'timezone' => $settings->timezone,
                'date_format' => $settings->dateFormat,
                'updated_at' => $now,
                'id' => self::ROW_ID,
            ],
        );
    }
}