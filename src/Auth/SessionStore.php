<?php

declare(strict_types=1);

namespace Stead\Auth;

/**
 * The session state an authentication flow needs.
 *
 * Implemented by {@see NativeSessionStore} for real requests and by
 * {@see ArraySessionStore} in tests, so authentication logic can be exercised
 * without starting a PHP session or emitting headers.
 */
interface SessionStore
{
    /** Session key holding the authenticated user id. */
    public const USER_KEY = 'user_id';

    public function start(): void;

    public function isStarted(): bool;

    public function id(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Issues a new session identifier, optionally discarding the old record.
     * Used on privilege establishment to prevent session fixation.
     */
    public function regenerate(bool $deleteOld = true): void;

    /**
     * Clears session data and destroys the underlying record.
     */
    public function destroy(): void;

    /**
     * Writes pending data and closes the session for this request.
     */
    public function save(): void;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;
}
