<?php

declare(strict_types=1);

namespace Stead\View;

use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeManifestReader;
use Stead\Themes\ThemeRepository;
use Stead\Themes\ThemeSettingsRepository;
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
 * re-resolved on every render so an admin activation takes effect on the
 * next request even if this renderer is reused.
 *
 * When a {@see ThemeManifestReader}/{@see ThemeSettingsRepository}/{@see
 * ThemeRepository} trio is supplied, a `theme_settings` global is exposed
 * to templates and re-synced per render — same pattern as `syncThemePaths`:
 * stored values fall back to manifest defaults, dormant keys (removed
 * from a re-uploaded manifest) are kept out of the global without being
 * deleted from the database.
 */
final class TwigRenderer implements Renderer
{
    private Environment $twig;
    private FilesystemLoader $loader;
    private ActiveThemeResolver $themes;
    private ?ThemeRepository $themeRepository;
    private ?ThemeManifestReader $manifestReader;
    private ?ThemeSettingsRepository $themeSettingsRepository;
    private string $defaultTemplates;
    private bool $hasThemeSettingsGlobal;

    /** @var array<string, mixed>|null Cached global; null forces a re-resolve next render. */
    private ?array $themeSettingsGlobal = null;

    public function __construct(
        Configuration $config,
        ActiveThemeResolver $themes,
        ?ThemeRepository $themeRepository = null,
        ?ThemeManifestReader $manifestReader = null,
        ?ThemeSettingsRepository $themeSettingsRepository = null,
    ) {
        $this->themes = $themes;
        $this->themeRepository = $themeRepository;
        $this->manifestReader = $manifestReader;
        $this->themeSettingsRepository = $themeSettingsRepository;
        $this->defaultTemplates = $config->path('paths.templates');
        $this->loader = new FilesystemLoader();
        $this->syncThemePaths();

        $cache = $config->path('paths.cache') . '/twig';

        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $debug = $config->isDevelopment() || $config->getBool('app.debug', false);

        $this->twig = new Environment($this->loader, [
            'autoescape' => 'html',
            'cache' => $cache,
            'strict_variables' => true,
            'auto_reload' => $debug,
        ]);

        $this->hasThemeSettingsGlobal = $themeRepository !== null
            && $manifestReader !== null
            && $themeSettingsRepository !== null;
        if ($this->hasThemeSettingsGlobal) {
            $this->twig->addGlobal('theme_settings', []);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $this->syncThemePaths();
        if ($this->hasThemeSettingsGlobal) {
            $this->syncThemeSettingsGlobal();
        }
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

        $this->themeSettingsGlobal = null;
    }

    /**
     * Re-resolves `theme_settings` from the active theme's manifest and
     * stored values. Empty when no theme is active, when the active
     * theme declares no settings, or when the active theme is missing
     * from disk — the same empty-dict semantics as the admin form's
     * empty state.
     */
    private function syncThemeSettingsGlobal(): void
    {
        if (
            $this->themeRepository === null
            || $this->manifestReader === null
            || $this->themeSettingsRepository === null
        ) {
            return;
        }

        $active = $this->themeRepository->findActive();
        if ($active === null) {
            $this->themeSettingsGlobal = [];
            $this->twig->addGlobal('theme_settings', []);
            return;
        }

        $directory = $this->themes->resolveThemeDirectory();
        if ($directory === null) {
            $this->themeSettingsGlobal = [];
            $this->twig->addGlobal('theme_settings', []);
            return;
        }

        $manifest = $this->manifestReader->readFrom($directory);
        $schema = $manifest['settings'] ?? [];
        $values = $schema === []
            ? []
            : $this->themeSettingsRepository->valuesFor($active->slug, $schema);

        $this->themeSettingsGlobal = $values;
        $this->twig->addGlobal('theme_settings', $values);
    }
}
