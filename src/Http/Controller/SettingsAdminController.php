<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Console\Seeder;
use Stead\Exception\SafeException;
use Stead\Settings\Settings;
use Stead\Settings\SettingsRepository;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only site settings: site name, timezone, and date format.
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

        $candidate = new Settings($siteName, $timezone, $dateFormat);

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
        return $this->html($this->renderer->render('admin/settings/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'values' => [
                'site_name' => $values->siteName,
                'timezone' => $values->timezone,
                'date_format' => $values->dateFormat,
            ],
            'errors' => $errors,
            'flash' => $flash,
            'action_url' => '/admin/settings',
            'seed_url' => '/admin/settings/seed-default-collections',
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

    private function html(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}