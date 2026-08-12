<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Exception\SafeException;
use Stead\Invites\Invite;
use Stead\Invites\InviteRepository;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public invite-acceptance flow: validate the token (exists, unexpired,
 * unused), collect a name and password, create the user with the invited
 * role, and mark the invite accepted.
 *
 * All failure modes (unknown token, expired, already accepted) collapse to
 * the same generic error so a third party cannot probe for valid invite
 * values by reading the response.
 */
final class InviteAcceptController
{
    private const GENERIC_INVALID = 'This invite link is no longer valid.';

    public function __construct(
        private readonly Renderer $renderer,
        private readonly InviteRepository $invites,
        private readonly UserRepository $users,
        private readonly AuthenticationService $auth,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function show(Request $request, array $parameters = []): Response
    {
        $token = (string) ($parameters['token'] ?? '');
        $invite = $this->lookupValid($token);

        if ($invite === null) {
            return $this->html($this->renderer->render('invite/invalid', [
                'message' => self::GENERIC_INVALID,
            ]));
        }

        return $this->html($this->renderer->render('invite/accept', [
            'token' => $token,
            'email' => $invite->email,
            'role_name' => $invite->roleName,
            'values' => ['name' => '', 'password' => ''],
            'errors' => [],
            'action_url' => '/invite/accept/' . $token,
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function submit(Request $request, array $parameters = []): Response
    {
        $token = (string) ($parameters['token'] ?? '');
        $invite = $this->lookupValid($token);
        if ($invite === null) {
            return $this->html($this->renderer->render('invite/invalid', [
                'message' => self::GENERIC_INVALID,
            ]));
        }

        $name = trim((string) $request->request->get('name', ''));
        $password = (string) $request->request->get('password', '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = ['Enter your name.'];
        }
        if (strlen($password) < PasswordHasher::MIN_LENGTH) {
            $errors['password'] = [sprintf('Password must be at least %d characters.', PasswordHasher::MIN_LENGTH)];
        }
        if (strlen($password) > PasswordHasher::MAX_LENGTH) {
            $errors['password'] = [sprintf('Password must be at most %d bytes.', PasswordHasher::MAX_LENGTH)];
        }

        if ($errors !== []) {
            return $this->html($this->renderer->render('invite/accept', [
                'token' => $token,
                'email' => $invite->email,
                'role_name' => $invite->roleName,
                'values' => ['name' => $name, 'password' => ''],
                'errors' => $errors,
                'action_url' => '/invite/accept/' . $token,
            ]));
        }

        try {
            $user = $this->users->create(
                $invite->email,
                $name,
                $password,
                $invite->roleName,
                true,
            );
        } catch (SafeException $e) {
            return $this->html($this->renderer->render('invite/accept', [
                'token' => $token,
                'email' => $invite->email,
                'role_name' => $invite->roleName,
                'values' => ['name' => $name, 'password' => ''],
                'errors' => ['__schema' => [$e->publicMessage()]],
                'action_url' => '/invite/accept/' . $token,
            ]));
        }

        $this->invites->markAccepted($invite->id);

        // Establish a session so the new user lands in the admin shell.
        $this->auth->attempt($invite->email, $password, $request);

        return new RedirectResponse('/admin', Response::HTTP_SEE_OTHER);
    }

    /**
     * Returns the invite only when it exists, is unexpired, and has not been
     * accepted or revoked. Any other outcome returns null so the caller can
     * surface a generic error.
     */
    private function lookupValid(string $token): ?Invite
    {
        if ($token === '') {
            return null;
        }
        $invite = $this->invites->findByToken($token);
        if ($invite === null) {
            return null;
        }
        if (!$invite->isPending(gmdate('Y-m-d H:i:s'))) {
            return null;
        }
        return $invite;
    }

    private function html(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
