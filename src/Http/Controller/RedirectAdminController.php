<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Content\RedirectRepository;
use Stead\Exception\SafeException;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only CRUD for manual redirects.
 *
 * Slug changes auto-create redirects in {@see \Stead\Content\EntryRepository};
 * this controller exposes the same store to admins who need to add or
 * remove redirects by hand (e.g. when a path needs to point at a different
 * collection entirely).
 */
final class RedirectAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly RedirectRepository $redirects,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);
        return $this->renderList($actor, [], '', []);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function store(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $oldPath = $this->normalizePath((string) $request->request->get('old_path', ''));
        $newPath = $this->normalizePath((string) $request->request->get('new_path', ''));

        $errors = [];
        if ($oldPath === '') {
            $errors['old_path'] = ['Old path is required.'];
        } elseif ($oldPath === $newPath) {
            $errors['new_path'] = ['New path must differ from the old path.'];
        }
        if ($newPath === '') {
            $errors['new_path'] = ['New path is required.'];
        }

        if ($errors !== []) {
            return $this->renderList($actor, $errors, '', [
                'old_path' => $oldPath,
                'new_path' => $newPath,
            ]);
        }

        $this->redirects->upsert($oldPath, $newPath);

        return new RedirectResponse('/admin/redirects', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function destroy(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);
        $id = (int) ($parameters['id'] ?? 0);
        if ($id > 0) {
            $this->redirects->delete($id);
        }
        return new RedirectResponse('/admin/redirects', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array{old_path?: string, new_path?: string} $values
     */
    private function renderList(User $actor, array $errors, string $flash, array $values): Response
    {
        $rows = [];
        foreach ($this->redirects->all() as $redirect) {
            $rows[] = [
                'id' => $redirect->id(),
                'old_path' => $redirect->oldPath(),
                'new_path' => $redirect->newPath(),
                'created_at' => $redirect->createdAt(),
            ];
        }

        return $this->html($this->renderer->render('admin/redirects/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'rows' => $rows,
            'values' => $values,
            'errors' => $errors,
            'flash' => $flash,
            'action_url' => '/admin/redirects',
        ]));
    }

    private function normalizePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($value[0] !== '/') {
            $value = '/' . $value;
        }
        return $value;
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