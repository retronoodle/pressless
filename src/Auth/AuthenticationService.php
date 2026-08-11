<?php

declare(strict_types=1);

namespace Stead\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates users and manages the authenticated session lifecycle.
 *
 * Failures are reported to callers as a plain null so the caller cannot
 * accidentally surface whether an email address exists.
 */
final class AuthenticationService
{
    /**
     * A real bcrypt hash used to equalize work when no user matches, so a
     * missing address is not distinguishable from a wrong password by timing.
     */
    private const TIMING_EQUALIZER_HASH = '$2y$12$JrlRjzSIoffKYU69uOCTneBMzaFasNCE1yexmEZWK9cYO6h8HRFNW';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly PasswordHasher $hasher,
        private readonly SessionStore $store,
        private readonly int $lifetimeSeconds = 7200,
    ) {
    }

    /**
     * Verifies credentials and, on success, establishes an authenticated session.
     */
    public function attempt(string $email, string $password, ?Request $request = null): ?User
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $this->hasher->verify($password, self::TIMING_EQUALIZER_HASH);
            return null;
        }

        $verified = $this->hasher->verify($password, $user->passwordHash);

        if (!$verified || !$user->isActive) {
            return null;
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->establishSession($user, $request);

        return $user;
    }

    /**
     * Returns the authenticated user for the current request, or null.
     *
     * The database record is the authority: an expired, revoked, or missing
     * record is treated as unauthenticated and the stale session is cleared.
     */
    public function currentUser(?Request $request = null): ?User
    {
        $this->store->start();

        $userId = $this->store->get(SessionStore::USER_KEY);
        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        $sessionId = $this->store->id();
        if ($sessionId === '' || $this->sessions->findActive($sessionId) === null) {
            $this->store->destroy();
            return null;
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$user->isActive) {
            $this->logout();
            return null;
        }

        $this->sessions->touch($sessionId, $this->lifetimeSeconds);

        return $user;
    }

    public function check(?Request $request = null): bool
    {
        return $this->currentUser($request) !== null;
    }

    /**
     * Invalidates the session record and clears the native session so the same
     * identifier cannot reach a protected route again.
     */
    public function logout(): void
    {
        $this->store->start();

        $sessionId = $this->store->id();
        if ($sessionId !== '') {
            $this->sessions->delete($sessionId);
        }

        $this->store->destroy();
    }

    private function establishSession(User $user, ?Request $request): void
    {
        $this->store->start();

        // New identifier on privilege establishment; the pre-login id is dropped.
        $this->store->regenerate(true);

        $this->store->set(SessionStore::USER_KEY, $user->id);
        $this->store->set('authenticated_at', time());

        $this->sessions->persist(
            $this->store->id(),
            $user->id,
            SessionPayload::encode($this->store->all()),
            $this->lifetimeSeconds,
            $request?->getClientIp() ?? '',
            (string) ($request?->headers->get('User-Agent') ?? ''),
        );
    }
}
