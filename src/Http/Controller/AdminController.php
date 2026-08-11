<?php

declare(strict_types=1);

namespace Pressless\Http\Controller;

use Pressless\Auth\User;
use Pressless\View\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the authenticated admin shell.
 *
 * The route is wrapped by the authentication guard, which sets the `user`
 * request attribute before this handler runs.
 */
final class AdminController
{
    public function __construct(private readonly Renderer $renderer)
    {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $user = $request->attributes->get('user');

        return new Response(
            $this->renderer->render('admin', [
                'user_name' => $user instanceof User ? $user->name : '',
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
