<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Stead\Themes\Theme;
use Stead\Themes\ThemeInstaller;
use Stead\Themes\ThemeRepository;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only theme upload, activation, and deletion.
 */
final class ThemesAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly ThemeRepository $themes,
        private readonly ThemeInstaller $installer,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);
        $flash = (string) $request->query->get('flash', '');
        $error = (string) $request->query->get('error', '');
        return $this->renderIndex($actor, $error !== '' ? [$error] : [], $flash);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function upload(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->renderIndex($actor, ['Please choose a ZIP file to upload.'], '');
        }

        try {
            $theme = $this->installer->install($file);
        } catch (SafeException $e) {
            return $this->renderIndex($actor, [$e->publicMessage()], '');
        }

        return new RedirectResponse(
            '/admin/themes?flash=' . urlencode(sprintf('Theme "%s" uploaded.', $theme->name)),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function activate(Request $request, array $parameters = []): Response
    {
        $this->requireAdmin($request);
        $id = (int) ($parameters['id'] ?? 0);
        if ($id < 1) {
            return new RedirectResponse(
                '/admin/themes?error=' . urlencode('Theme not found.'),
                Response::HTTP_SEE_OTHER,
            );
        }
        try {
            $this->themes->activate($id);
        } catch (SafeException $e) {
            return new RedirectResponse(
                '/admin/themes?error=' . urlencode($e->publicMessage()),
                Response::HTTP_SEE_OTHER,
            );
        }
        $this->clearPublicPageCache();
        return new RedirectResponse(
            '/admin/themes?flash=' . urlencode('Theme activated.'),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function delete(Request $request, array $parameters = []): Response
    {
        $this->requireAdmin($request);
        $id = (int) ($parameters['id'] ?? 0);
        $theme = $id > 0 ? $this->themes->findById($id) : null;
        if ($theme === null) {
            return new RedirectResponse(
                '/admin/themes?error=' . urlencode('Theme not found.'),
                Response::HTTP_SEE_OTHER,
            );
        }
        try {
            $this->themes->delete($id);
        } catch (SafeException $e) {
            return new RedirectResponse(
                '/admin/themes?error=' . urlencode($e->publicMessage()),
                Response::HTTP_SEE_OTHER,
            );
        }
        $this->installer->removeThemeFiles($theme->slug);
        return new RedirectResponse(
            '/admin/themes?flash=' . urlencode(sprintf('Theme "%s" deleted.', $theme->name)),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param list<string> $errors
     */
    private function renderIndex(User $actor, array $errors, string $flash): Response
    {
        $rows = [];
        foreach ($this->themes->listAll() as $theme) {
            $rows[] = $this->present($theme);
        }

        return $this->html($this->renderer->render('admin/themes/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'rows' => $rows,
            'errors' => $errors,
            'flash' => $flash,
            'action_url' => '/admin/themes',
        ]));
    }

    /**
     * @return array{id: int, slug: string, name: string, version: string, author: string, is_active: bool}
     */
    private function present(Theme $theme): array
    {
        return [
            'id' => $theme->id,
            'slug' => $theme->slug,
            'name' => $theme->name,
            'version' => $theme->version,
            'author' => $theme->author,
            'is_active' => $theme->isActive,
        ];
    }

    private function clearPublicPageCache(): void
    {
        $dir = $this->config->path('paths.cache') . '/public/pages';
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
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
