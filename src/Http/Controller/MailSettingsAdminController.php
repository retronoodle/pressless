<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Stead\Mail\MailSettings;
use Stead\Mail\MailSettingsRepository;
use Stead\Mail\Message;
use Stead\Mail\SmtpTransport;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only SMTP settings: configure host/port/encryption/credentials,
 * trigger a test send to the admin's own address.
 *
 * Save persists via {@see MailSettingsRepository}; the form re-loads with the
 * password field blank (the stored credential is never echoed back).
 */
final class MailSettingsAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly MailSettingsRepository $settings,
        private readonly SmtpTransport $transport,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        return $this->renderForm($actor, $this->settings->load()->withoutPassword(), [], '');
    }

    /**
     * @param array<string, string> $parameters
     */
    public function save(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $host = trim((string) $request->request->get('host', ''));
        $portRaw = trim((string) $request->request->get('port', '587'));
        $encryption = (string) $request->request->get('encryption', MailSettings::ENCRYPTION_STARTTLS);
        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');

        $errors = [];
        if ($host === '') {
            $errors['host'] = ['SMTP host is required.'];
        }
        if ($portRaw === '' || !ctype_digit($portRaw)) {
            $errors['port'] = ['Port must be a positive integer.'];
        }
        $port = (int) $portRaw;
        if ($port < 1 || $port > 65535) {
            $errors['port'] = ['Port must be between 1 and 65535.'];
        }
        if (!in_array($encryption, MailSettings::ENCRYPTIONS, true)) {
            $errors['encryption'] = ['Encryption mode is not recognised.'];
        }

        // When saving, an empty password means "leave the stored value
        // untouched" — re-saves shouldn't wipe the credential. The form
        // surfaces an explicit "leave blank to keep current" hint.
        $current = $this->settings->load();
        $storedPassword = $current->password;
        $incomingPassword = $password;
        $finalPassword = $incomingPassword === '' ? $storedPassword : $incomingPassword;

        $candidate = new MailSettings($host, $port, $encryption, $username, $finalPassword);

        if ($errors !== []) {
            return $this->renderForm(
                $actor,
                (new MailSettings($host, $port, $encryption, $username, '')),
                $errors,
                '',
            );
        }

        $this->settings->save($candidate);

        return new RedirectResponse('/admin/mail-settings', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function testSend(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $settings = $this->settings->load();
        if (!$settings->isConfigured()) {
            return $this->renderForm(
                $actor,
                $settings->withoutPassword(),
                [],
                'Save the SMTP host before sending a test email.',
            );
        }

        $fromAddress = $this->config->getString('mail.from_email');
        $fromName = $this->config->getString('mail.from_name');
        $fromHeader = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress;

        $message = new Message(
            $fromAddress,
            $actor->email,
            'Stead SMTP test email',
            sprintf(
                "This is a test message from your Stead site.\r\n\r\n"
                . "If you received it, your SMTP settings are working.\r\n"
                . "Sent to: %s\r\nSent at: %s\r\n",
                $actor->email,
                gmdate('r'),
            ),
            ['From' => $fromHeader],
        );

        try {
            $this->transport->sendWithSettings($message, $settings);
            return $this->renderForm(
                $actor,
                $settings->withoutPassword(),
                [],
                'Test email sent to ' . $actor->email . '.',
            );
        } catch (SafeException $e) {
            return $this->renderForm(
                $actor,
                $settings->withoutPassword(),
                [],
                'Test send failed: ' . $e->publicMessage(),
            );
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function renderForm(User $actor, MailSettings $displayValues, array $errors, string $flash): Response
    {
        return $this->html($this->renderer->render('admin/mail-settings/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'values' => [
                'host' => $displayValues->host,
                'port' => $displayValues->port,
                'encryption' => $displayValues->encryption,
                'username' => $displayValues->username,
                'password' => '',
            ],
            'encryptions' => MailSettings::ENCRYPTIONS,
            'errors' => $errors,
            'flash' => $flash,
            'action_url' => '/admin/mail-settings',
            'test_send_url' => '/admin/mail-settings/test-send',
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
