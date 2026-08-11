<?php

declare(strict_types=1);

namespace Stead\View;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;
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
 *
 * When `theme.active` is set in configuration and resolves to an existing
 * directory under `paths.theme`, the theme directory is registered ahead of
 * the default templates directory. Twig's FilesystemLoader returns the
 * first match, so theme templates win and unmatched names fall back to the
 * default directory.
 */
final class TwigRenderer implements Renderer
{
    private Environment $twig;

    public function __construct(Configuration $config)
    {
        $defaultTemplates = $config->path('paths.templates');
        $loader = new FilesystemLoader();

        $themePath = $this->resolveThemePath($config);
        if ($themePath !== null) {
            $loader->addPath($themePath);
        }
        $loader->addPath($defaultTemplates);

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

    private function resolveThemePath(Configuration $config): ?string
    {
        $active = $config->getString('theme.active');
        if ($active === '') {
            return null;
        }

        $themesRoot = $config->getString('paths.theme');
        if ($themesRoot === '') {
            return null;
        }

        $candidate = rtrim($config->projectRoot(), '/') . '/' . trim($themesRoot, '/') . '/' . trim($active, '/');
        return is_dir($candidate) ? $candidate : null;
    }
}