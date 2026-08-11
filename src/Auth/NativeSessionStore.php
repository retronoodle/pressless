<?php

declare(strict_types=1);

namespace Pressless\Auth;

use Pressless\Config\Configuration;
use Pressless\Exception\SafeException;

/**
 * Wraps PHP's native session API with the project's cookie policy and the
 * database-backed handler.
 */
final class NativeSessionStore implements SessionStore
{
    private bool $started = false;

    public function __construct(
        private readonly Configuration $config,
        private readonly ?DatabaseSessionHandler $handler = null,
    ) {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (headers_sent($file, $line)) {
            throw new SafeException('Cannot start a session after output has been sent.');
        }

        if ($this->handler !== null) {
            session_set_save_handler($this->handler, true);
        }

        session_name($this->config->getString('sessions.name', 'pressless_session'));
        session_set_cookie_params($this->cookieParams());

        if (!session_start()) {
            throw new SafeException('Could not start the session.');
        }

        $this->started = true;
    }

    /**
     * Cookie attributes for the configured environment. `Secure` is enabled
     * whenever the configuration requests it or the app URL is HTTPS.
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    public function cookieParams(): array
    {
        $sameSite = $this->config->getString('sessions.cookie_samesite', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        $secure = $this->config->getBool('sessions.cookie_secure', false)
            || str_starts_with($this->config->getString('app.url'), 'https://');

        // SameSite=None is only valid on secure cookies.
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        return [
            'lifetime' => $this->config->getInt('sessions.lifetime', 7200),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $this->config->getBool('sessions.cookie_httponly', true),
            'samesite' => $sameSite,
        ];
    }

    public function isStarted(): bool
    {
        return $this->started && session_status() === PHP_SESSION_ACTIVE;
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(bool $deleteOld = true): void
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        session_regenerate_id($deleteOld);
    }

    public function destroy(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION = [];

        // Expire the cookie so the browser stops presenting the old identifier.
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'pressless_session', '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
        $this->started = false;
    }

    public function save(): void
    {
        if ($this->isStarted()) {
            session_write_close();
            $this->started = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $session */
        $session = $_SESSION ?? [];
        return $session;
    }
}
