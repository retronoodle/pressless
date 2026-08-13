<?php

declare(strict_types=1);

namespace Stead\Tests\Support;

use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeManifestReader;
use Stead\Themes\ThemeRepository;
use Stead\Themes\ThemeSettingsRepository;
use Stead\View\TwigRenderer;

final class TestRenderer
{
    public static function twig(Configuration $config, Connection $connection): TwigRenderer
    {
        $themeRepository = new ThemeRepository($connection);
        return new TwigRenderer(
            $config,
            new ActiveThemeResolver($themeRepository, $config),
            $themeRepository,
            new ThemeManifestReader(),
            new ThemeSettingsRepository($connection),
        );
    }
}
