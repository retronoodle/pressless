<?php

declare(strict_types=1);

namespace Stead\Installer;

use Stead\Exception\SafeException;

/**
 * A tiny session wrapper for the installer wizard.
 *
 * The installer runs before `.env` exists, so it cannot reuse the application's
 * configured session store. It uses PHP's native session machinery directly with
 * a short-lived, installer-specific cookie name. State is keyed under a single
 * namespace so a future visit to `/install/*` after a partial completion can
 * resume from where it left off instead of restarting at step 1.
 */
final class NativeInstallerSession implements InstallerSessionStore
{
    private const COOKIE_NAME = 'stead_installer';
    private const SESSION_KEY = 'installer';

    private bool $started = false;

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (headers_sent($file, $line)) {
            throw new SafeException('Cannot start the installer session after output has been sent.');
        }

        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new SafeException('Could not start the installer session.');
        }
        $this->started = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWizard(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return [];
        }
        /** @var array<string, mixed> $wizard */
        $wizard = $_SESSION[self::SESSION_KEY];
        return $wizard;
    }

    /**
     * @param array<string, mixed> $wizard
     */
    public function setWizard(array $wizard): void
    {
        $_SESSION[self::SESSION_KEY] = $wizard;
    }

    public function clearWizard(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(self::COOKIE_NAME, '', [
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

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }
}