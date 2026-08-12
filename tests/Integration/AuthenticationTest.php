<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\AuthGuard;
use Stead\Auth\DatabaseSessionHandler;
use Stead\Auth\LoginAttemptRepository;
use Stead\Auth\LoginThrottle;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionPayload;
use Stead\Auth\SessionRepository;
use Stead\Auth\SessionStore;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Http\Controller\LoginController;
use Stead\View\SimpleRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticationTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private UserRepository $users;
    private SessionRepository $sessions;
    private PasswordHasher $hasher;
    private ArraySessionStore $store;
    private AuthenticationService $auth;

    protected static function driver(): string
    {
        return 'sqlite';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator->migrate();

        // Cost 4 keeps bcrypt cheap in tests without changing behavior.
        $this->hasher = new PasswordHasher(4);
        $this->users = new UserRepository($this->connection, $this->hasher);
        $this->sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($this->sessions);
        $this->auth = new AuthenticationService(
            $this->users,
            $this->sessions,
            $this->hasher,
            $this->store,
            3600,
        );
    }

    private function makeUser(bool $isActive = true, string $email = 'ada@example.com'): User
    {
        return $this->users->create($email, 'Ada Lovelace', self::PASSWORD, User::ROLE_ADMIN, $isActive);
    }

    // --- Hashing -----------------------------------------------------------

    public function testPasswordIsStoredAsABcryptHash(): void
    {
        $user = $this->makeUser();

        $this->assertNotSame(self::PASSWORD, $user->passwordHash);
        $this->assertStringStartsWith('$2y$', $user->passwordHash);
        $this->assertTrue($this->hasher->verify(self::PASSWORD, $user->passwordHash));
        $this->assertFalse($this->hasher->verify('wrong-password', $user->passwordHash));
    }

    public function testHashesAreSaltedPerUser(): void
    {
        $first = $this->makeUser(email: 'one@example.com');
        $second = $this->makeUser(email: 'two@example.com');

        $this->assertNotSame($first->passwordHash, $second->passwordHash);
    }

    public function testUserDoesNotExposeItsHashWhenSerialized(): void
    {
        $user = $this->makeUser();

        $this->assertStringNotContainsString(
            $user->passwordHash,
            (string) json_encode($user),
        );
    }

    // --- Login -------------------------------------------------------------

    public function testSuccessfulLoginEstablishesASession(): void
    {
        $user = $this->makeUser();

        $authenticated = $this->auth->attempt('ada@example.com', self::PASSWORD);

        $this->assertNotNull($authenticated);
        $this->assertSame($user->id, $authenticated->id);
        $this->assertSame($user->id, $this->store->get(SessionStore::USER_KEY));

        $record = $this->sessions->findActive($this->store->id());
        $this->assertNotNull($record);
        $this->assertSame($user->id, (int) $record['user_id']);
    }

    public function testLoginIsCaseInsensitiveOnEmail(): void
    {
        $this->makeUser();

        $this->assertNotNull($this->auth->attempt('ADA@Example.com', self::PASSWORD));
    }

    public function testLoginRegeneratesTheSessionIdentifier(): void
    {
        $this->makeUser();

        $this->store->start();
        $before = $this->store->id();

        $this->auth->attempt('ada@example.com', self::PASSWORD);
        $after = $this->store->id();

        $this->assertNotSame($before, $after);
        $this->assertContains($before, $this->store->destroyedIds());
        $this->assertNull($this->sessions->findActive($before));
    }

    public function testUnknownEmailFails(): void
    {
        $this->makeUser();

        $this->assertNull($this->auth->attempt('nobody@example.com', self::PASSWORD));
        $this->assertNull($this->store->get(SessionStore::USER_KEY));
    }

    public function testWrongPasswordFails(): void
    {
        $this->makeUser();

        $this->assertNull($this->auth->attempt('ada@example.com', 'not-the-password'));
        $this->assertNull($this->store->get(SessionStore::USER_KEY));
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $this->makeUser(isActive: false);

        $this->assertNull($this->auth->attempt('ada@example.com', self::PASSWORD));
        $this->assertNull($this->store->get(SessionStore::USER_KEY));
        $this->assertSame(0, $this->sessionCount());
    }

    public function testFailedLoginCreatesNoSessionRecord(): void
    {
        $this->makeUser();

        $this->auth->attempt('ada@example.com', 'nope');

        $this->assertSame(0, $this->sessionCount());
    }

    // --- Session lifecycle -------------------------------------------------

    public function testCurrentUserResolvesAnAuthenticatedSession(): void
    {
        $user = $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $current = $this->auth->currentUser();

        $this->assertNotNull($current);
        $this->assertSame($user->id, $current->id);
    }

    public function testExpiredSessionIsTreatedAsUnauthenticated(): void
    {
        $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);
        $sessionId = $this->store->id();

        // Expire the record without touching the in-memory session data.
        $this->connection->execute(
            'UPDATE sessions SET expires_at = :expired WHERE id = :id',
            ['expired' => SessionRepository::formatTimestamp(time() - 60), 'id' => $sessionId],
        );

        $this->assertNull($this->sessions->findActive($sessionId));
        $this->assertNull($this->auth->currentUser());
        $this->assertNull($this->store->get(SessionStore::USER_KEY));
    }

    public function testRevokedSessionIsTreatedAsUnauthenticated(): void
    {
        $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $this->sessions->delete($this->store->id());

        $this->assertNull($this->auth->currentUser());
    }

    public function testDeactivatingAUserEndsTheSession(): void
    {
        $user = $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $this->connection->execute(
            'UPDATE users SET is_active = 0 WHERE id = :id',
            ['id' => $user->id],
        );

        $this->assertNull($this->auth->currentUser());
        $this->assertSame(0, $this->sessionCount());
    }

    public function testLogoutInvalidatesTheSession(): void
    {
        $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);
        $sessionId = $this->store->id();

        $this->auth->logout();

        $this->assertNull($this->sessions->findActive($sessionId));
        $this->assertSame(0, $this->sessionCount());
        $this->assertNull($this->auth->currentUser());
    }

    public function testExpiredSessionsAreCollected(): void
    {
        $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $this->connection->execute(
            'UPDATE sessions SET expires_at = :expired',
            ['expired' => SessionRepository::formatTimestamp(time() - 60)],
        );

        $this->assertSame(1, $this->sessions->deleteExpired());
        $this->assertSame(0, $this->sessionCount());
    }

    public function testSessionsAreRevokedForAUser(): void
    {
        $user = $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $this->sessions->deleteForUser($user->id);

        $this->assertSame(0, $this->sessionCount());
    }

    // --- Session handler ---------------------------------------------------

    public function testHandlerPersistsOnlyAuthenticatedSessions(): void
    {
        $user = $this->makeUser();
        $handler = new DatabaseSessionHandler($this->sessions, 3600);

        $this->assertTrue($handler->write('anon-session', SessionPayload::encode(['flash' => 'hello'])));
        $this->assertSame(0, $this->sessionCount(), 'Anonymous sessions must not be persisted.');

        $payload = SessionPayload::encode([SessionStore::USER_KEY => $user->id]);
        $this->assertTrue($handler->write('auth-session', $payload));
        $this->assertSame(1, $this->sessionCount());
        $this->assertSame($payload, $handler->read('auth-session'));
    }

    public function testHandlerReturnsEmptyStringForExpiredOrMissingSessions(): void
    {
        $handler = new DatabaseSessionHandler($this->sessions, 3600);

        $this->assertSame('', $handler->read('never-existed'));
        $this->assertFalse($handler->validateId('never-existed'));
    }

    public function testHandlerDestroyRemovesTheRecord(): void
    {
        $user = $this->makeUser();
        $handler = new DatabaseSessionHandler($this->sessions, 3600);
        $handler->write('doomed', SessionPayload::encode([SessionStore::USER_KEY => $user->id]));

        $this->assertTrue($handler->destroy('doomed'));
        $this->assertSame(0, $this->sessionCount());
    }

    public function testUserIdIsExtractedFromEncodedPayload(): void
    {
        $payload = SessionPayload::encode([SessionStore::USER_KEY => 42, 'authenticated_at' => 1700000000]);

        $this->assertSame(42, DatabaseSessionHandler::extractUserId($payload));
        $this->assertNull(DatabaseSessionHandler::extractUserId(''));
        $this->assertNull(DatabaseSessionHandler::extractUserId(SessionPayload::encode(['other' => 1])));
    }

    // --- Controller behavior ----------------------------------------------

    private function controller(): LoginController
    {
        $throttle = new LoginThrottle(new LoginAttemptRepository($this->connection), $this->config);

        return new LoginController($this->auth, new SimpleRenderer(), $throttle);
    }

    public function testSuccessfulLoginRedirectsToTheAdminShell(): void
    {
        $this->makeUser();

        $response = $this->controller()->login(Request::create('/admin/login', 'POST', [
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin', $response->getTargetUrl());
    }

    public function testFailedLoginGivesAGenericError(): void
    {
        $this->makeUser();

        $unknown = $this->controller()->login(Request::create('/admin/login', 'POST', [
            'email' => 'nobody@example.com',
            'password' => self::PASSWORD,
        ]));
        $wrongPassword = $this->controller()->login(Request::create('/admin/login', 'POST', [
            'email' => 'ada@example.com',
            'password' => 'wrong',
        ]));

        foreach ([$unknown, $wrongPassword] as $response) {
            $this->assertSame(401, $response->getStatusCode());
            $body = (string) $response->getContent();
            $this->assertStringContainsString('do not match our records', $body);
            $this->assertStringNotContainsString('inactive', strtolower($body));
            $this->assertStringNotContainsString('no such user', strtolower($body));
        }

        // Both failure modes must be byte-identical so neither reveals existence.
        $this->assertSame($unknown->getStatusCode(), $wrongPassword->getStatusCode());
    }

    public function testLoginFormRedirectsAnAlreadyAuthenticatedUser(): void
    {
        $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $response = $this->controller()->show(Request::create('/admin/login'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin', $response->getTargetUrl());
    }

    public function testLogoutRedirectsToLogin(): void
    {
        $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $response = $this->controller()->logout(Request::create('/admin/logout', 'POST'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/login', $response->getTargetUrl());
        $this->assertSame(0, $this->sessionCount());
    }

    public function testLoginHonoursASafeRedirectTarget(): void
    {
        $this->makeUser();

        $response = $this->controller()->login(Request::create('/admin/login', 'POST', [
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'redirect' => '/admin/entries',
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/entries', $response->getTargetUrl());
    }

    public function testLoginRefusesAnOffsiteRedirectTarget(): void
    {
        $this->makeUser();

        $response = $this->controller()->login(Request::create('/admin/login', 'POST', [
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'redirect' => 'https://evil.example.com/steal',
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin', $response->getTargetUrl());
    }

    // --- Guard -------------------------------------------------------------

    public function testGuardRedirectsUnauthenticatedRequests(): void
    {
        $guard = new AuthGuard($this->auth);
        $protected = $guard->protect(
            static fn(): \Symfony\Component\HttpFoundation\Response
                => new \Symfony\Component\HttpFoundation\Response('secret'),
        );

        $response = $protected(Request::create('/admin'), []);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/login?redirect=%2Fadmin', $response->getTargetUrl());
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function testGuardAllowsAuthenticatedRequestsAndSuppliesTheUser(): void
    {
        $user = $this->makeUser();
        $this->auth->attempt('ada@example.com', self::PASSWORD);

        $guard = new AuthGuard($this->auth);
        $protected = $guard->protect(
            static function (Request $request): \Symfony\Component\HttpFoundation\Response {
                $current = $request->attributes->get('user');
                return new \Symfony\Component\HttpFoundation\Response(
                    $current instanceof User ? $current->email : 'none',
                );
            },
        );

        $response = $protected(Request::create('/admin'), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($user->email, $response->getContent());
    }

    private function sessionCount(): int
    {
        $row = $this->connection->fetchOne('SELECT COUNT(*) AS c FROM sessions');

        return (int) ($row['c'] ?? 0);
    }
}
