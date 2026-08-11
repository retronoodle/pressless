<?php

declare(strict_types=1);

namespace Pressless\Http\Controller;

use Pressless\Auth\User;
use Pressless\Content\CollectionRepository;
use Pressless\Content\EntryRepository;
use Pressless\View\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the authenticated admin shell.
 *
 * The route is wrapped by the authentication guard, which sets the `user`
 * request attribute before this handler runs. The dashboard view reports
 * collection and entry counts so the empty-state CTA appears only when the
 * site is genuinely empty.
 */
final class AdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly CollectionRepository $collections,
        private readonly EntryRepository $entries,
    ) {
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
                'collection_count' => $this->collections->count(),
                'entry_count' => $this->entries->count(),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
