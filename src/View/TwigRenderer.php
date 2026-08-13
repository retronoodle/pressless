<?php

declare(strict_types=1);

namespace Stead\View;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Stead\Themes\ActiveThemeResolver;
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
 * When {@see ActiveThemeResolver} finds an active theme directory, that
 * directory is registered ahead of the default templates directory. Twig's
 * FilesystemLoader returns the first match, so theme templates win and
 * unmatched names fall back to the default directory. The active theme is
 * re-resolved on each render so an admin activation takes effect on the
 * next request even if this renderer is reused.
 */
final class TwigRenderer implements Renderer
{
    private Environment $twig;
    private FilesystemLoader $loader;
    private ActiveThemeResolver $themes;
    private string $defaultTemplates;

    public function __construct(Configuration $config, ActiveThemeResolver $themes)
    {
        $this->themes = $themes;
        $this->defaultTemplates = $config->path('paths.templates');
        $this->loader = new FilesystemLoader();
        $this->syncThemePaths();

        $cache = $config->path('paths.cache') . '/twig';

        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $this->twig = new Environment($this->loader, [
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
        $this->syncThemePaths();
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

    private function syncThemePaths(): void
    {
        $paths = [];
        $themePath = $this->themes->resolveThemeDirectory();
        if ($themePath !== null) {
            $paths[] = $themePath;
        }
        $paths[] = $this->defaultTemplates;
        $this->loader->setPaths($paths);
    }
}
