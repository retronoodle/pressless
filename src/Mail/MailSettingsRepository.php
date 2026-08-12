<?php

declare(strict_types=1);

namespace Stead\Mail;

use Stead\Database\Connection;

/**
 * Loads and persists the single-row SMTP configuration.
 *
 * The seeded row (id=1) is upserted in place — there is only ever one config
 * record. The repository deliberately exposes the password to the transport
 * but the admin form path uses {@see MailSettings::withoutPassword()} so the
 * stored credential never round-trips through a renderer.
 */
final class MailSettingsRepository
{
    private const ROW_ID = 1;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(): MailSettings
    {
        $row = $this->connection->fetchOne(
            'SELECT host, port, encryption, username, password FROM mail_settings WHERE id = :id',
            ['id' => self::ROW_ID],
        );
        if ($row === null) {
            return new MailSettings('', 587, MailSettings::ENCRYPTION_STARTTLS, '', '');
        }
        return MailSettings::fromRow($row);
    }

    public function save(MailSettings $settings): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'UPDATE mail_settings
                SET host = :host,
                    port = :port,
                    encryption = :encryption,
                    username = :username,
                    password = :password,
                    updated_at = :updated_at
              WHERE id = :id',
            [
                'host' => $settings->host,
                'port' => $settings->port,
                'encryption' => $settings->encryption,
                'username' => $settings->username,
                'password' => $settings->password,
                'updated_at' => $now,
                'id' => self::ROW_ID,
            ],
        );
    }
}
