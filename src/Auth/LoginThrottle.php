<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Config\Configuration;

/**
 * Tracks failed login attempts and locks out further attempts when an
 * identifier (email or IP) exceeds the configured failure threshold within
 * the configured window.
 *
 * The throttle is checked before credential verification so a locked-out
 * request skips bcrypt work entirely. Lockout duration escalates with
 * exponential backoff on repeated lockouts, capped at the configured
 * maximum.
 */
final class LoginThrottle
{
    private const MAX_BACKOFF_ATTEMPTS = 30;

    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly Configuration $config,
    ) {
    }

    /**
     * Returns true when either the email or the IP is currently locked out.
     */
    public function isLocked(string $email, string $ipAddress): bool
    {
        return $this->lockRemainingSeconds($email, $ipAddress) > 0;
    }

    /**
     * Records a failed attempt for both identifiers. Either identifier may
     * independently cross the threshold on subsequent checks.
     */
    public function recordFailure(string $email, string $ipAddress): void
    {
        $this->attempts->record($email, $ipAddress, false, $this->windowSeconds());
    }

    /**
     * Records a successful login: clears the email's prior failures so the
     * same user isn't penalized by their own earlier typos. IP failures are
     * intentionally left intact.
     */
    public function recordSuccess(string $email): void
    {
        $this->attempts->clearForEmail($email);
    }

    /**
     * Seconds remaining on the current lockout for either identifier, or 0.
     * Used both by {@see isLocked()} and by tests that want to observe the
     * backoff curve directly.
     */
    public function lockRemainingSeconds(string $email, string $ipAddress): int
    {
        $emailRemaining = $this->identifierRemainingSeconds(
            LoginAttemptRepository::normalizeEmail($email),
            'email',
        );
        $ipRemaining = $this->identifierRemainingSeconds($ipAddress, 'ip_address');

        return max($emailRemaining, $ipRemaining);
    }

    private function identifierRemainingSeconds(string $value, string $column): int
    {
        $failures = $column === 'email'
            ? $this->attempts->countRecentEmailFailures($value, $this->windowSeconds())
            : $this->attempts->countRecentIpFailures($value, $this->windowSeconds());

        if ($failures < $this->maxAttempts()) {
            return 0;
        }

        $latest = $this->attempts->latestFailureAt($column, $value, $this->windowSeconds());
        if ($latest === null) {
            return 0;
        }

        $lockout = $this->computeLockoutSeconds($failures);
        $elapsed = time() - $latest;

        return max(0, $lockout - $elapsed);
    }

    /**
     * Exponential backoff: base * 2^(failures - threshold), capped at the
     * configured maximum. Extra failures beyond a sane ceiling still
     * contribute to the cap rather than overflowing the int.
     */
    private function computeLockoutSeconds(int $failures): int
    {
        $base = $this->lockoutBaseSeconds();
        $max = $this->lockoutMaxSeconds();
        $threshold = $this->maxAttempts();
        $excess = max(0, min(self::MAX_BACKOFF_ATTEMPTS, $failures - $threshold));

        if ($excess === 0) {
            return $base;
        }

        $multiplier = 1 << $excess;
        $candidate = $base * $multiplier;

        return $candidate > $max ? $max : $candidate;
    }

    private function maxAttempts(): int
    {
        return $this->config->getInt('login.max_attempts', 5);
    }

    private function windowSeconds(): int
    {
        return $this->config->getInt('login.window_seconds', 900);
    }

    private function lockoutBaseSeconds(): int
    {
        return $this->config->getInt('login.lockout_base_seconds', 60);
    }

    private function lockoutMaxSeconds(): int
    {
        return $this->config->getInt('login.lockout_max_seconds', 3600);
    }
}
