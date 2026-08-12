<?php

declare(strict_types=1);

namespace Stead\Mail;

use Stead\Exception\SafeException;

/**
 * Hand-rolled SMTP transport.
 *
 * Supports the common path (LOGIN/PLAIN auth, STARTTLS on 587, implicit TLS
 * on 465) and surfaces connection/auth/send failures as a SafeException so
 * the caller never silently drops a message.
 *
 * Deliberately not a library — see Phase 8 design ("Hand-rolled SMTP client
 * over a library"). Each step of the SMTP dialogue is in its own small
 * method so the call sequence stays readable.
 */
final class SmtpTransport
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;
    public const CRLF = "\r\n";

    public function __construct(
        private readonly MailSettingsRepository $settings,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public function send(Message $message): void
    {
        $settings = $this->settings->load();
        if (!$settings->isConfigured()) {
            throw new SafeException(
                'SMTP host is not configured.',
                ['reason' => 'mail_not_configured'],
            );
        }

        $this->sendWithSettings($message, $settings);
    }

    /**
     * Sends using an explicit settings record (test send path, or when a
     * caller wants to override what is currently persisted).
     */
    public function sendWithSettings(Message $message, MailSettings $settings): void
    {
        $socket = $this->connect($settings);
        try {
            $this->readGreeting($socket);
            $this->hello($socket, $settings);
            if ($settings->encryption === MailSettings::ENCRYPTION_STARTTLS) {
                $this->startTls($socket, $settings->host);
                $this->hello($socket, $settings);
            }
            if ($settings->username !== '') {
                $this->authenticate($socket, $settings->username, $settings->password);
            }
            $this->mailFrom($socket, $message->from);
            $this->rcptTo($socket, $message->to);
            $this->data($socket, $message);
        } finally {
            $this->quit($socket);
            @fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function connect(MailSettings $settings)
    {
        $transport = match ($settings->encryption) {
            MailSettings::ENCRYPTION_TLS => 'ssl',
            default => 'tcp',
        };

        $remote = sprintf('%s://%s:%d', $transport, $settings->host, $settings->port);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new SafeException(
                'Could not connect to SMTP server.',
                [
                    'host' => $settings->host,
                    'port' => $settings->port,
                    'reason' => 'connect_failed',
                ],
            );
        }
        stream_set_timeout($socket, $this->timeoutSeconds);
        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function readGreeting($socket): void
    {
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '220')) {
            throw new SafeException(
                'SMTP server did not greet.',
                ['reason' => 'no_greeting', 'line' => $line],
            );
        }
    }

    /**
     * @param resource $socket
     */
    private function hello($socket, MailSettings $settings): void
    {
        $hostname = $settings->host !== '' ? $settings->host : 'localhost';
        $this->sendLine($socket, 'EHLO ' . $hostname);
        $this->readMultiline($socket, '250');
    }

    /**
     * @param resource $socket
     */
    private function startTls($socket, string $host): void
    {
        $this->sendLine($socket, 'STARTTLS');
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '220')) {
            throw new SafeException(
                'SMTP server refused STARTTLS.',
                ['reason' => 'starttls_refused', 'line' => $line],
            );
        }
        $cryptoType = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoType |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoType |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        $enabled = @stream_socket_enable_crypto($socket, true, $cryptoType);
        if ($enabled !== true) {
            throw new SafeException(
                'Could not enable TLS on SMTP connection.',
                ['reason' => 'tls_handshake_failed', 'host' => $host],
            );
        }
    }

    /**
     * @param resource $socket
     */
    private function authenticate($socket, string $username, string $password): void
    {
        $this->sendLine($socket, 'AUTH LOGIN');
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '334')) {
            // Some servers advertise only PLAIN; fall through if username/password
            // is short enough to safely use PLAIN over an already-TLS-protected socket.
            if (str_starts_with($line, '504') || str_starts_with($line, '502')) {
                $this->sendLine(
                    $socket,
                    'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password),
                );
                $line = $this->readLine($socket);
                if (!str_starts_with($line, '235')) {
                    throw new SafeException(
                        'SMTP authentication failed.',
                        ['reason' => 'auth_failed', 'line' => $line],
                    );
                }
                return;
            }
            throw new SafeException(
                'SMTP AUTH LOGIN refused.',
                ['reason' => 'auth_loggin_refused', 'line' => $line],
            );
        }
        $this->sendLine($socket, base64_encode($username));
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '334')) {
            throw new SafeException(
                'SMTP server rejected username.',
                ['reason' => 'username_rejected', 'line' => $line],
            );
        }
        $this->sendLine($socket, base64_encode($password));
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '235')) {
            throw new SafeException(
                'SMTP authentication failed.',
                ['reason' => 'auth_failed', 'line' => $line],
            );
        }
    }

    /**
     * @param resource $socket
     */
    private function mailFrom($socket, string $from): void
    {
        if ($from === '') {
            throw new SafeException(
                'Cannot send mail without a From address.',
                ['reason' => 'no_from'],
            );
        }
        $this->sendLine($socket, 'MAIL FROM:<' . $from . '>');
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '250')) {
            throw new SafeException(
                'SMTP server rejected MAIL FROM.',
                ['reason' => 'mail_from_rejected', 'line' => $line],
            );
        }
    }

    /**
     * @param resource $socket
     */
    private function rcptTo($socket, string $to): void
    {
        $this->sendLine($socket, 'RCPT TO:<' . $to . '>');
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '250') && !str_starts_with($line, '251')) {
            throw new SafeException(
                'SMTP server rejected RCPT TO.',
                ['reason' => 'rcpt_to_rejected', 'line' => $line],
            );
        }
    }

    /**
     * @param resource $socket
     */
    private function data($socket, Message $message): void
    {
        $this->sendLine($socket, 'DATA');
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '354')) {
            throw new SafeException(
                'SMTP server refused DATA.',
                ['reason' => 'data_refused', 'line' => $line],
            );
        }
        $payload = $this->buildPayload($message);
        $this->writeAll($socket, $payload);
        $this->writeAll($socket, self::CRLF . '.' . self::CRLF);
        $line = $this->readLine($socket);
        if (!str_starts_with($line, '250')) {
            throw new SafeException(
                'SMTP server rejected message body.',
                ['reason' => 'message_rejected', 'line' => $line],
            );
        }
    }

    private function buildPayload(Message $message): string
    {
        $headers = $message->headers;
        $headers['From'] ??= $message->from;
        $headers['To'] = $message->to;
        $headers['Subject'] = $message->subject;
        $headers['Date'] = gmdate('r');
        $headers['MIME-Version'] = '1.0';
        $headers['Content-Type'] = 'text/plain; charset=utf-8';

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = sprintf('%s: %s', $name, $this->encodeHeader($value));
        }
        $head = implode(self::CRLF, $lines) . self::CRLF . self::CRLF;
        $body = $this->dotStuff($message->body);
        return $head . $body;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            return '=?utf-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /**
     * @param resource $socket
     */
    private function quit($socket): void
    {
        try {
            $this->sendLine($socket, 'QUIT');
            $this->readLine($socket);
        } catch (\Throwable) {
            // best-effort — the caller is about to close the socket anyway
        }
    }

    /**
     * @param resource $socket
     */
    private function sendLine($socket, string $line): void
    {
        $this->writeAll($socket, $line . self::CRLF);
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = @fwrite($socket, $remaining);
            if ($written === false || $written === 0) {
                throw new SafeException(
                    'Failed writing to SMTP socket.',
                    ['reason' => 'write_failed'],
                );
            }
            $remaining = substr($remaining, $written);
        }
    }

    /**
     * @param resource $socket
     */
    private function readLine($socket): string
    {
        $line = @fgets($socket, 1024);
        if ($line === false || $line === '') {
            throw new SafeException(
                'SMTP connection closed unexpectedly.',
                ['reason' => 'connection_closed'],
            );
        }
        return rtrim($line, "\r\n");
    }

    /**
     * @param resource $socket
     */
    private function readMultiline($socket, string $expectedPrefix): void
    {
        $first = $this->readLine($socket);
        if (!str_starts_with($first, $expectedPrefix)) {
            throw new SafeException(
                'Unexpected SMTP response.',
                ['reason' => 'unexpected_response', 'line' => $first],
            );
        }
        if (strlen($first) >= 4 && $first[3] === ' ') {
            return;
        }
        while (true) {
            $next = $this->readLine($socket);
            if (strlen($next) >= 4 && $next[3] === ' ') {
                return;
            }
        }
    }
}
