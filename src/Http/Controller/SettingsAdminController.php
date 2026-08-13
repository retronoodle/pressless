<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Console\Seeder;
use Stead\Content\EntryRepository;
use Stead\Exception\SafeException;
use Stead\Settings\Settings;
use Stead\Settings\SettingsRepository;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeManifestReader;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only site settings: site name, timezone, date format, and the
 * homepage type override.
 *
 * Save persists via {@see SettingsRepository}; the form re-loads with the
 * submitted values on a validation failure so the admin doesn't lose what
 * they typed.
 */
final class SettingsAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly SettingsRepository $settings,
        private readonly Seeder $seeder,
        private readonly EntryRepository $entries,
        private readonly ActiveThemeResolver $themeResolver,
        private readonly ThemeManifestReader $manifestReader,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $flash = (string) $request->query->get('flash', '');
        $errorParam = (string) $request->query->get('error', '');
        $errors = $errorParam === '' ? [] : ['_form' => [$errorParam]];

        return $this->renderForm($actor, $this->settings->load(), $errors, $flash);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function save(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $siteName = trim((string) $request->request->get('site_name', ''));
        $timezone = trim((string) $request->request->get('timezone', Settings::DEFAULT_TIMEZONE));
        $dateFormat = trim((string) $request->request->get('date_format', Settings::DEFAULT_DATE_FORMAT));

        $errors = [];
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = ['Timezone is not a valid identifier.'];
        }
        if ($dateFormat === '') {
            $errors['date_format'] = ['Date format is required.'];
        }

        $homepage = $this->normaliseHomepageSubmission($request, $errors);

        $candidate = new Settings(
            $siteName,
            $timezone,
            $dateFormat,
            $homepage['homepageType'],
            $homepage['homepagePageId'],
        );

        if ($errors !== []) {
            return $this->renderForm($actor, $candidate, $errors, '');
        }

        $this->settings->save($candidate);

        return new RedirectResponse('/admin/settings', Response::HTTP_SEE_OTHER);
    }

    /**
     * Admin-only "Seed default collections" action for sites that skipped
     * the installer (or installed before this collection seeding existed).
     * Idempotent: existing collections are left untouched, and the redirect
     * reports how many were created — or "already present" when none were.
     *
     * @param array<string, string> $parameters
     */
    public function seedDefaultCollections(Request $request, array $parameters = []): Response
    {
        $this->requireAdmin($request);

        try {
            $created = $this->seeder->seedDefaultCollections();
        } catch (\Throwable $e) {
            $message = $e instanceof SafeException ? $e->publicMessage() : $e->getMessage();
            return new RedirectResponse(
                '/admin/settings?error=' . urlencode('Seeding failed: ' . $message),
                Response::HTTP_SEE_OTHER,
            );
        }

        if ($created === 0) {
            $flash = 'Default collections are already present.';
        } else {
            $flash = sprintf('Created %d default collection%s.', $created, $created === 1 ? '' : 's');
        }

        return new RedirectResponse(
            '/admin/settings?flash=' . urlencode($flash),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function renderForm(User $actor, Settings $values, array $errors, string $flash): Response
    {
        $homepage = $this->resolveHomepageForDisplay($values);

        return $this->html($this->renderer->render('admin/settings/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'values' => [
                'site_name' => $values->siteName,
                'timezone' => $values->timezone,
                'date_format' => $values->dateFormat,
                'homepage_type' => $values->homepageType ?? '',
                'homepage_page_id' => $values->homepagePageId ?? '',
            ],
            'homepage' => $homepage,
            'errors' => $errors,
            'flash' => $flash,
            'action_url' => '/admin/settings',
            'seed_url' => '/admin/settings/seed-default-collections',
        ]));
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array{homepageType: ?string, homepagePageId: ?int}
     */
    private function normaliseHomepageSubmission(Request $request, array &$errors): array
    {
        $action = (string) $request->request->get('homepage_action', 'use_theme_default');
        if ($action === 'use_theme_default') {
            return ['homepageType' => null, 'homepagePageId' => null];
        }
        if ($action !== 'static_page') {
            $errors['homepage_type'] = ['Pick a homepage action.'];
            return ['homepageType' => null, 'homepagePageId' => null];
        }

        $rawId = $request->request->get('homepage_page_id', '');
        if (!is_numeric($rawId)) {
            $errors['homepage_page_id'] = ['Pick an entry to use as the homepage.'];
            return ['homepageType' => Settings::HOMEPAGE_TYPE_STATIC_PAGE, 'homepagePageId' => null];
        }
        $id = (int) $rawId;
        $entry = $this->entries->find($id);
        if ($entry === null) {
            $errors['homepage_page_id'] = ['Pick an entry that exists.'];
            return ['homepageType' => Settings::HOMEPAGE_TYPE_STATIC_PAGE, 'homepagePageId' => null];
        }

        return ['homepageType' => Settings::HOMEPAGE_TYPE_STATIC_PAGE, 'homepagePageId' => $id];
    }

    /**
     * @return array{
     *     effective_type: string,
     *     effective_source: string,
     *     theme_default: ?string,
     *     has_override: bool,
     *     pages: list<array{id: int, title: string, slug: string, collection_slug: string, collection_name: string}>
     * }
     */
    private function resolveHomepageForDisplay(Settings $values): array
    {
        $themeDefault = $this->readActiveThemeHomepageType();
        $hasOverride = $values->homepageType !== null;
        $effective = $hasOverride ? $values->homepageType : ($themeDefault ?? ThemeManifestReader::HOMEPAGE_TYPE_COLLECTION_LIST);
        $source = $hasOverride
            ? 'override'
            : ($themeDefault !== null ? 'theme' : 'fallback');

        return [
            'effective_type' => $effective,
            'effective_source' => $source,
            'theme_default' => $themeDefault,
            'has_override' => $hasOverride,
            'pages' => $this->entries->listPublishedForPicker(),
        ];
    }

    private function readActiveThemeHomepageType(): ?string
    {
        try {
            $directory = $this->themeResolver->resolveThemeDirectory();
        } catch (\Throwable) {
            return null;
        }
        if ($directory === null) {
            return null;
        }
        try {
            $manifest = $this->manifestReader->readFrom($directory);
        } catch (\Throwable) {
            return null;
        }
        return $manifest['homepage_type'] ?? null;
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
