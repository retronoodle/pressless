<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeManifestReader;
use Stead\Themes\ThemeRepository;
use Stead\Themes\ThemeSettingsRepository;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only screen for configuring the active theme's declared settings.
 *
 * Reads the manifest schema from disk per request (single source of truth),
 * resolves stored values via {@see ThemeSettingsRepository::valuesFor()}, and
 * renders one form control per declared setting. `save` normalises submitted
 * values per setting `type` (boolean → "0"/"1", select constrained to declared
 * `options`) and persists via the repository.
 */
final class ThemeSettingsAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly ThemeRepository $themes,
        private readonly ActiveThemeResolver $themeResolver,
        private readonly ThemeManifestReader $manifestReader,
        private readonly ThemeSettingsRepository $settings,
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

        $active = $this->themes->findActive();
        if ($active === null) {
            return $this->renderEmpty($actor, $flash, $error, 'No theme is active.');
        }

        $directory = $this->themeResolver->resolveThemeDirectory();
        if ($directory === null) {
            return $this->renderEmpty(
                $actor,
                $flash,
                $error,
                sprintf('The active theme "%s" is missing from disk.', $active->slug),
            );
        }

        $manifest = $this->manifestReader->readFrom($directory);
        if ($manifest === null || $manifest['settings'] === []) {
            return $this->renderEmpty(
                $actor,
                $flash,
                $error,
                sprintf('The active theme "%s" does not declare any settings.', $active->slug),
            );
        }

        $values = $this->settings->valuesFor($active->slug, $manifest['settings']);
        $values = $this->normaliseForDisplay($manifest['settings'], $values);

        return $this->html($this->renderer->render('admin/theme-settings/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'active_theme' => [
                'slug' => $active->slug,
                'name' => $manifest['name'],
            ],
            'fields' => $manifest['settings'],
            'values' => $values,
            'errors' => [],
            'flash' => $flash,
            'error' => $error,
            'action_url' => '/admin/theme-settings',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function save(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $active = $this->themes->findActive();
        if ($active === null) {
            return new RedirectResponse(
                '/admin/theme-settings?error=' . urlencode('No theme is active.'),
                Response::HTTP_SEE_OTHER,
            );
        }

        $directory = $this->themeResolver->resolveThemeDirectory();
        if ($directory === null) {
            return new RedirectResponse(
                '/admin/theme-settings?error=' . urlencode('The active theme is missing from disk.'),
                Response::HTTP_SEE_OTHER,
            );
        }

        $manifest = $this->manifestReader->readFrom($directory);
        if ($manifest === null || $manifest['settings'] === []) {
            return new RedirectResponse(
                '/admin/theme-settings?error=' . urlencode('The active theme does not declare any settings.'),
                Response::HTTP_SEE_OTHER,
            );
        }

        $errors = [];
        $values = [];
        foreach ($manifest['settings'] as $field) {
            $key = $field['key'];
            $raw = $request->request->get($key);
            $normalised = $this->normaliseSubmitted($field, is_string($raw) ? $raw : '', $errors);
            $values[$key] = $normalised;
        }

        if ($errors !== []) {
            $display = $this->normaliseForDisplay($manifest['settings'], $values);
            return $this->html($this->renderer->render('admin/theme-settings/index', [
                'user_name' => $actor->name,
                'user_role' => $actor->roleName,
                'active_theme' => [
                    'slug' => $active->slug,
                    'name' => $manifest['name'],
                ],
                'fields' => $manifest['settings'],
                'values' => $display,
                'errors' => $errors,
                'flash' => '',
                'error' => '',
                'action_url' => '/admin/theme-settings',
            ]));
        }

        $this->settings->save($active->slug, $values);
        $this->clearPublicPageCache();

        return new RedirectResponse(
            '/admin/theme-settings?flash=' . urlencode('Theme settings saved.'),
            Response::HTTP_SEE_OTHER,
        );
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

    /**
     * @param list<array{key: string, label: string, type: string, default: string, options: list<string>}> $settings
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function normaliseForDisplay(array $settings, array $values): array
    {
        $out = [];
        foreach ($settings as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? '';
            if ($field['type'] === 'select') {
                $options = $field['options'];
                if (!in_array($value, $options, true)) {
                    $value = (string) $field['default'];
                }
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Coerces a submitted value to the storage-friendly string representation
     * for its declared type. Select values that don't match declared options
     * fall back to the manifest default rather than failing the form.
     *
     * @param array{key: string, label: string, type: string, default: string, options: list<string>} $field
     * @param array<string, list<string>> $errors
     */
    private function normaliseSubmitted(array $field, string $raw, array &$errors): string
    {
        $type = $field['type'];
        $key = $field['key'];
        if ($type === 'boolean') {
            return $raw === '1' ? '1' : '0';
        }
        if ($type === 'select') {
            $options = $field['options'];
            if ($options === [] || in_array($raw, $options, true)) {
                return $raw;
            }
            return (string) $field['default'];
        }
        if ($type === 'text' || $type === 'color' || $type === 'image' || $type === 'textarea') {
            return $raw;
        }
        $errors[$key][] = sprintf('Unsupported setting type "%s".', $type);
        return $raw;
    }

    private function renderEmpty(User $actor, string $flash, string $error, string $reason): Response
    {
        return $this->html($this->renderer->render('admin/theme-settings/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'active_theme' => null,
            'fields' => [],
            'values' => [],
            'errors' => [],
            'flash' => $flash,
            'error' => $error,
            'empty_reason' => $reason,
            'action_url' => '/admin/theme-settings',
        ]));
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
}
