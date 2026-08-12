<?php

declare(strict_types=1);

namespace Stead\Installer;

use Stead\Exception\SafeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A Twig renderer scoped to the installer's templates.
 *
 * The installer runs before the application's `paths.templates` setting is
 * guaranteed to be valid, so this renderer loads from a fixed path relative to
 * the project root. It still uses the standard Twig cache directory so the
 * existing setup carries over without modification.
 */
final class InstallerRenderer
{
    private Environment $twig;

    public function __construct(string $projectRoot, string $cacheDir)
    {
        $templateDir = $projectRoot . '/templates/installer';
        $loader = new FilesystemLoader($templateDir);

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'cache' => $cacheDir . '/twig-installer',
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
                sprintf('Could not render installer template "%s".', $template),
                ['template' => $template],
                $e,
            );
        }
    }
}