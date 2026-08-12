<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\PermissionRepository;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Content\CollectionRepository;
use Stead\Exception\SafeException;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only permission editor: which actions the `editor` and `author`
 * roles are granted on each collection. The matrix is updated in a single
 * submit; rows that are checked are added, rows that are unchecked are
 * removed.
 */
final class PermissionAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly UserRepository $users,
        private readonly CollectionRepository $collections,
        private readonly PermissionRepository $permissions,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $roles = array_values(array_filter(
            $this->users->listRoles(),
            static fn(array $role): bool => $role['name'] !== User::ROLE_ADMIN,
        ));

        $collections = [];
        foreach ($this->collections->all() as $collection) {
            $collections[] = [
                'id' => $collection->id(),
                'slug' => $collection->slug(),
                'name' => $collection->name(),
            ];
        }

        $matrix = $this->permissions->fullMatrix();

        $rows = [];
        foreach ($roles as $role) {
            $cells = [];
            foreach ($collections as $collection) {
                $actions = [];
                foreach (PermissionRepository::ACTIONS as $action) {
                    $actions[$action] = isset($matrix[$role['id']][$collection['id']][$action]);
                }
                $cells[$collection['id']] = $actions;
            }
            $rows[] = [
                'id' => $role['id'],
                'name' => $role['name'],
                'cells' => $cells,
            ];
        }

        return $this->html($this->renderer->render('admin/permissions/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'roles' => $rows,
            'collections' => $collections,
            'actions' => PermissionRepository::ACTIONS,
            'users_url' => '/admin/users',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function update(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $roles = array_values(array_filter(
            $this->users->listRoles(),
            static fn(array $role): bool => $role['name'] !== User::ROLE_ADMIN,
        ));
        $collections = $this->collections->all();

        // For each (role, collection) pair, derive the desired action set
        // from the submitted form. Submitting an unchecked checkbox is
        // indistinguishable from "not submitted" — that's fine; both
        // mean "no grant".
        $submitted = $request->request->all('permissions');
        if (!is_array($submitted)) {
            $submitted = [];
        }

        foreach ($roles as $role) {
            foreach ($collections as $collection) {
                $key = $role['id'] . ':' . $collection->id();
                $actions = [];
                if (isset($submitted[$key]) && is_array($submitted[$key])) {
                    foreach ($submitted[$key] as $action) {
                        if (is_string($action) && in_array($action, PermissionRepository::ACTIONS, true)) {
                            $actions[] = $action;
                        }
                    }
                }
                $this->permissions->setActions($role['id'], $collection->id(), $actions);
            }
        }

        return new RedirectResponse('/admin/permissions', Response::HTTP_SEE_OTHER);
    }

    private function requireAdmin(Request $request): User
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof User || !$user->isAdmin()) {
            throw new SafeException('Admin required.');
        }
        return $user;
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
