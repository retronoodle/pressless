<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Stead\Invites\InviteRepository;
use Stead\Mail\MailSettingsRepository;
use Stead\Mail\Message;
use Stead\Mail\SmtpTransport;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only invite flow: list pending/recent invites, generate a new
 * invite for an email + role, send the acceptance link via SMTP.
 */
final class InviteAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly InviteRepository $invites,
        private readonly UserRepository $users,
        private readonly SmtpTransport $transport,
        private readonly MailSettingsRepository $mailSettings,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function create(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $roles = $this->nonAdminRoles();

        return $this->renderForm(
            $actor,
            $roles,
            ['email' => '', 'role_id' => $roles[0]['id'] ?? 0],
            [],
            '',
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function store(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $email = trim((string) $request->request->get('email', ''));
        $roleId = (int) $request->request->get('role_id', 0);

        $roles = $this->nonAdminRoles();
        $allRoles = $this->users->listRoles();
        $roleNames = [];
        foreach ($allRoles as $r) {
            $roleNames[(int) $r['id']] = (string) $r['name'];
        }
        $roleIds = array_map(static fn(array $r): int => (int) $r['id'], $allRoles);

        $errors = [];
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = ['Enter a valid email address.'];
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors['email'] = ['A user with this email already exists.'];
        }
        if (!in_array($roleId, $roleIds, true) || ($roleNames[$roleId] ?? '') === User::ROLE_ADMIN) {
            $errors['role_id'] = ['Pick a valid role.'];
        }

        if ($errors !== []) {
            return $this->renderForm(
                $actor,
                $roles,
                ['email' => $email, 'role_id' => $roleId],
                $errors,
                '',
            );
        }

        try {
            $result = $this->invites->create($email, $roleId, $actor->id);
        } catch (SafeException $e) {
            return $this->renderForm(
                $actor,
                $roles,
                ['email' => $email, 'role_id' => $roleId],
                ['__schema' => [$e->publicMessage()]],
                '',
            );
        }

        try {
            $this->sendInviteEmail($result['invite']->email, $roleNames[$roleId], $result['token']);
            $flash = sprintf('Invite sent to %s.', $result['invite']->email);
        } catch (SafeException $e) {
            // Invite row is persisted — surface the send failure so the admin can
            // retry by issuing a fresh invite.
            $flash = sprintf(
                'Invite saved but the email could not be sent: %s',
                $e->publicMessage(),
            );
        }

        return $this->renderForm(
            $actor,
            $roles,
            ['email' => '', 'role_id' => $roles[0]['id'] ?? 0],
            [],
            $flash,
        );
    }

    private function sendInviteEmail(string $toEmail, string $roleName, string $token): void
    {
        $settings = $this->mailSettings->load();
        if (!$settings->isConfigured()) {
            throw new SafeException(
                'SMTP host is not configured.',
                ['reason' => 'mail_not_configured'],
            );
        }

        $baseUrl = rtrim($this->config->getString('app.url', ''), '/');
        $acceptUrl = $baseUrl . '/invite/accept/' . $token;
        $fromAddress = $this->config->getString('mail.from_email');
        $fromName = $this->config->getString('mail.from_name');
        $fromHeader = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress;

        $body = sprintf(
            "You've been invited to join the Stead site as a %s.\r\n\r\n"
            . "Set your password by visiting:\r\n%s\r\n\r\n"
            . "This link expires in 7 days and can only be used once.\r\n",
            $roleName,
            $acceptUrl,
        );

        $message = new Message(
            $fromAddress,
            $toEmail,
            'You have been invited to join Stead',
            $body,
            ['From' => $fromHeader],
        );

        $this->transport->sendWithSettings($message, $settings);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function nonAdminRoles(): array
    {
        return array_values(array_filter(
            $this->users->listRoles(),
            static fn(array $role): bool => (string) $role['name'] !== User::ROLE_ADMIN,
        ));
    }

    /**
     * @param list<array{id: int, name: string}> $roles
     * @param array{email: string, role_id: int} $values
     * @param array<string, list<string>> $errors
     */
    private function renderForm(User $actor, array $roles, array $values, array $errors, string $flash): Response
    {
        return $this->html($this->renderer->render('admin/invites/new', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'roles' => $roles,
            'values' => $values,
            'errors' => $errors,
            'flash' => $flash,
            'action_url' => '/admin/invites/new',
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
