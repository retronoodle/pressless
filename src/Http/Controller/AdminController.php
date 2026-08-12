<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\AuthorizationService;
use Stead\Auth\User;
use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\EntryRepository;
use Stead\View\Renderer;
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
        private readonly AuthorizationService $authorization,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $user = $request->attributes->get('user');

        $visibleSlugs = $user instanceof User
            ? $this->authorization->grantedCollectionSlugs($user)
            : [];
        $collections = $this->visibleCollections($visibleSlugs);

        return new Response(
            $this->renderer->render('admin', [
                'user_name' => $user instanceof User ? $user->name : '',
                'user_role' => $user instanceof User ? $user->roleName : '',
                'collection_count' => count($collections),
                'entry_count' => $this->entries->count(),
                'visible_collections' => $collections,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * @param list<string> $visibleSlugs
     * @return list<array{slug: string, name: string, field_count: int}>
     */
    private function visibleCollections(array $visibleSlugs): array
    {
        if ($visibleSlugs === []) {
            return [];
        }
        $rows = [];
        foreach ($this->collections->all() as $collection) {
            if (!in_array($collection->slug(), $visibleSlugs, true)) {
                continue;
            }
            $rows[] = [
                'slug' => $collection->slug(),
                'name' => $collection->name(),
                'field_count' => count($collection->fields()),
            ];
        }
        return $rows;
    }
}
