<?php

declare(strict_types=1);

namespace Pressless\Http\Controller;

use Pressless\Auth\AuthenticationService;
use Pressless\Auth\AuthGuard;
use Pressless\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles the login form, credential submission, and logout.
 */
final class LoginController
{
    /**
     * A single message for every failure mode, so the response never reveals
     * whether an address exists or an account is inactive.
     */
    private const GENERIC_ERROR = 'Those credentials do not match our records.';

    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Renderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function show(Request $request, array $parameters = []): Response
    {
        if ($this->auth->check($request)) {
            return new RedirectResponse(AuthGuard::DEFAULT_TARGET);
        }

        $redirect = $request->query->get('redirect');

        return $this->render(
            Response::HTTP_OK,
            redirect: is_string($redirect) && AuthGuard::isSafeTarget($redirect) ? $redirect : '',
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function login(Request $request, array $parameters = []): Response
    {
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $redirect = $request->request->get('redirect');

        $email = is_string($email) ? trim($email) : '';
        $password = is_string($password) ? $password : '';
        $redirect = is_string($redirect) && AuthGuard::isSafeTarget($redirect) ? $redirect : '';

        if ($email === '' || $password === '') {
            return $this->render(Response::HTTP_UNAUTHORIZED, self::GENERIC_ERROR, $email, $redirect);
        }

        $user = $this->auth->attempt($email, $password, $request);

        if ($user === null) {
            return $this->render(Response::HTTP_UNAUTHORIZED, self::GENERIC_ERROR, $email, $redirect);
        }

        return new RedirectResponse(AuthGuard::resolveTarget($redirect === '' ? null : $redirect));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function logout(Request $request, array $parameters = []): Response
    {
        $this->auth->logout();

        return new RedirectResponse(AuthGuard::LOGIN_PATH);
    }

    private function render(int $status, string $error = '', string $email = '', string $redirect = ''): Response
    {
        return new Response(
            $this->renderer->render('login', [
                'error' => $error,
                'email' => $email,
                'redirect' => $redirect,
            ]),
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
