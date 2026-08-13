<?php

declare(strict_types=1);

namespace Stead\Tests\Support;

use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeRepository;
use Stead\View\TwigRenderer;

final class TestRenderer
{
    public static function twig(Configuration $config, Connection $connection): TwigRenderer
    {
        return new TwigRenderer(
            $config,
            new ActiveThemeResolver(new ThemeRepository($connection), $config),
        );
    }
}
