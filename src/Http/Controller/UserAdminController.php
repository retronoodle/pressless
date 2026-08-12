<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\PasswordHasher;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Exception\SafeException;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only user management: list, create, and change a user's role.
 *
 * Route registration places these behind the authorization wrapper so the
 * controller never sees a non-admin request — the explicit check is
 * defensive.
 */
final class UserAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $rows = [];
        foreach ($this->users->all() as $user) {
            $rows[] = [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role_id' => $user->roleId,
                'role_name' => $user->roleName,
                'is_active' => $user->isActive,
            ];
        }

        return $this->html($this->renderer->render('admin/users/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'users' => $rows,
            'roles' => $this->users->listRoles(),
            'new_user_url' => '/admin/users/new',
            'permissions_url' => '/admin/permissions',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function create(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        return $this->html($this->renderer->render('admin/users/new', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'roles' => $this->users->listRoles(),
            'values' => ['email' => '', 'name' => '', 'role_name' => User::ROLE_AUTHOR],
            'errors' => [],
            'action_url' => '/admin/users/new',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function store(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $email = trim((string) $request->request->get('email', ''));
        $name = trim((string) $request->request->get('name', ''));
        $password = (string) $request->request->get('password', '');
        $roleName = (string) $request->request->get('role_name', User::ROLE_AUTHOR);

        $values = [
            'email' => $email,
            'name' => $name,
            'role_name' => $roleName,
        ];
        $errors = $this->validateNewUser($email, $name, $password, $roleName);

        $user = null;
        if ($errors === []) {
            try {
                $user = $this->users->create($email, $name, $password, $roleName, true);
            } catch (SafeException $e) {
                $errors['__schema'] = [$e->publicMessage()];
            }
            if ($user !== null && !isset($errors['__schema'])) {
                return new RedirectResponse('/admin/users/' . $user->id . '/role', Response::HTTP_SEE_OTHER);
            }
        }

        return $this->html($this->renderer->render('admin/users/new', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'roles' => $this->users->listRoles(),
            'values' => $values,
            'errors' => $errors,
            'action_url' => '/admin/users/new',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function editRole(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);
        $id = (int) ($parameters['id'] ?? 0);
        $user = $this->users->findById($id);
        if ($user === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->html($this->renderer->render('admin/users/role', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'target' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role_name' => $user->roleName,
            ],
            'roles' => $this->users->listRoles(),
            'errors' => [],
            'action_url' => '/admin/users/' . $user->id . '/role',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function updateRole(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);
        $id = (int) ($parameters['id'] ?? 0);
        $user = $this->users->findById($id);
        if ($user === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $roleName = trim((string) $request->request->get('role_name', $user->roleName));
        $errors = [];
        if (!in_array($roleName, ['admin', 'editor', 'author'], true)) {
            $errors['role_name'] = ['Unknown role.'];
        }

        if ($errors === []) {
            $this->users->updateRole($user->id, $roleName);
            return new RedirectResponse('/admin/users', Response::HTTP_SEE_OTHER);
        }

        return $this->html($this->renderer->render('admin/users/role', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'target' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role_name' => $roleName,
            ],
            'roles' => $this->users->listRoles(),
            'errors' => $errors,
            'action_url' => '/admin/users/' . $user->id . '/role',
        ]));
    }

    private function requireAdmin(Request $request): User
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof User || !$user->isAdmin()) {
            throw new SafeException('Admin required.');
        }
        return $user;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateNewUser(string $email, string $name, string $password, string $roleName): array
    {
        $errors = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['Enter a valid email address.'];
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors['email'] = ['A user with this email already exists.'];
        }
        if ($name === '') {
            $errors['name'] = ['Name is required.'];
        }
        if (strlen($password) < 8) {
            $errors['password'] = ['Password must be at least 8 characters.'];
        }
        if (!in_array($roleName, ['admin', 'editor', 'author'], true)) {
            $errors['role_name'] = ['Unknown role.'];
        }
        return $errors;
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
