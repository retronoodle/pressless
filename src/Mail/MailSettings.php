<?php

declare(strict_types=1);

namespace Stead\Mail;

/**
 * The single-row SMTP configuration.
 *
 * The password is held so the transport can authenticate, but {@see displayFormValues()}
 * strips it before handing settings to a renderer so an admin reloading the
 * settings page never sees the previously saved password echoed back.
 */
final class MailSettings implements \JsonSerializable
{
    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_TLS = 'tls';

    public const ENCRYPTIONS = [
        self::ENCRYPTION_NONE,
        self::ENCRYPTION_STARTTLS,
        self::ENCRYPTION_TLS,
    ];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly string $password,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['host'] ?? ''),
            (int) ($row['port'] ?? 587),
            (string) ($row['encryption'] ?? self::ENCRYPTION_STARTTLS),
            (string) ($row['username'] ?? ''),
            (string) ($row['password'] ?? ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== '';
    }

    /**
     * Returns a settings record with the password cleared, safe to hand to a
     * form renderer so an admin reloading the page never sees their password.
     */
    public function withoutPassword(): self
    {
        return new self($this->host, $this->port, $this->encryption, $this->username, '');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
