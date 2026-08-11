<?php

declare(strict_types=1);

namespace Pressless\View;

use Pressless\Config\Configuration;
use Pressless\Exception\SafeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders Twig templates from the configured template paths with autoescaping
 * enabled and an isolated cache directory.
 *
 * Templates are loaded relative to {@see Configuration::path('paths.templates')}
 * so the project root resolver is the only place paths come from. A missing
 * template surfaces as {@see SafeException} so the kernel returns a controlled
 * 500 rather than leaking a stack trace to the browser.
 */
final class TwigRenderer implements Renderer
{
    private Environment $twig;

    public function __construct(Configuration $config)
    {
        $loader = new FilesystemLoader($config->path('paths.templates'));
        $cache = $config->path('paths.cache') . '/twig';

        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'cache' => $cache,
            'strict_variables' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        try {
            return $this->twig->render($template . '.twig', $data);
        } catch (\Twig\Error\Error $e) {
            throw new SafeException(
                sprintf('Could not render template "%s".', $template),
                ['template' => $template],
                $e,
            );
        }
    }
}