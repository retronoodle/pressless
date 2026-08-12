<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
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
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        return $this->renderForm($actor, $this->settings->load(), [], '');
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