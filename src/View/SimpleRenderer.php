<?php

declare(strict_types=1);

namespace Pressless\View;

/**
 * A dependency-free renderer producing minimal, fully escaped HTML.
 *
 * It exists so the authentication flow is complete and testable before the Twig
 * templates land; the Twig renderer replaces it behind {@see Renderer}.
 */
final class SimpleRenderer implements Renderer
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return match ($template) {
            'login' => $this->login($data),
            'admin' => $this->admin($data),
            default => $this->document('Pressless', '<p>' . self::e($template) . '</p>'),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function login(array $data): string
    {
        $error = isset($data['error']) ? (string) $data['error'] : '';
        $email = isset($data['email']) ? (string) $data['email'] : '';
        $redirect = isset($data['redirect']) ? (string) $data['redirect'] : '';

        $body = '<h1>Sign in to Pressless</h1>';

        if ($error !== '') {
            $body .= '<p role="alert" class="error">' . self::e($error) . '</p>';
        }

        $body .= '<form method="post" action="/admin/login">';

        if ($redirect !== '') {
            $body .= '<input type="hidden" name="redirect" value="' . self::e($redirect) . '">';
        }

        $body .= '<p><label for="email">Email</label>'
            . '<input type="email" id="email" name="email" value="' . self::e($email) . '" required autofocus></p>'
            . '<p><label for="password">Password</label>'
            . '<input type="password" id="password" name="password" required></p>'
            . '<p><button type="submit">Sign in</button></p>'
            . '</form>';

        return $this->document('Sign in', $body);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function admin(array $data): string
    {
        $name = isset($data['user_name']) ? (string) $data['user_name'] : '';

        $body = '<h1>Pressless admin</h1>'
            . '<p>Signed in as ' . self::e($name) . '.</p>'
            . '<form method="post" action="/admin/logout"><button type="submit">Sign out</button></form>';

        return $this->document('Admin', $body);
    }

    private function document(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title></head><body>' . $body . '</body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
