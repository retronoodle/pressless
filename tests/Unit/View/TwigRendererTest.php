<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Exception\SafeException;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeRepository;
use Stead\View\TwigRenderer;

final class TwigRendererTest extends TestCase
{
    private function config(): Configuration
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);

        file_put_contents($tmp . '/templates/greet.twig', 'Hello {{ name }}!');

        return new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
            ],
        ]);
    }

    private function renderer(Configuration $config): TwigRenderer
    {
        $connection = new Connection(new Configuration($config->projectRoot(), 'test', [
            'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
        ]));
        return new TwigRenderer(
            $config,
            new ActiveThemeResolver(new ThemeRepository($connection), $config),
        );
    }

    public function testRendersATemplateWithVariables(): void
    {
        $output = $this->renderer($this->config())->render('greet', ['name' => 'Ada']);

        $this->assertSame('Hello Ada!', $output);
    }

    public function testEscapesHtmlByDefault(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);
        file_put_contents($tmp . '/templates/raw.twig', '{{ value }}');

        $config = new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
            ],
        ]);

        $output = $this->renderer($config)->render('raw', ['value' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testMissingTemplateBecomesASafeException(): void
    {
        $renderer = $this->renderer($this->config());

        try {
            $renderer->render('does-not-exist');
            $this->fail('Expected SafeException for missing template.');
        } catch (SafeException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('Could not render template', $e->getMessage());
        }
    }

    /**
     * @return array{config: Configuration, root: string}
     */
    private function configWithTheme(string $activeTheme, ?string $themePath = null): array
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);

        file_put_contents($tmp . '/templates/greet.twig', 'default {{ name }}');
        file_put_contents($tmp . '/templates/only-default.twig', 'only default');

        $themeDir = $tmp . '/' . trim($themePath ?? 'themes', '/') . '/' . $activeTheme;
        mkdir($themeDir, 0775, true);
        file_put_contents($themeDir . '/greet.twig', 'theme {{ name }}');

        $config = new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
                'theme' => $themePath ?? 'themes',
            ],
            'theme' => ['active' => $activeTheme],
        ]);

        return ['config' => $config, 'root' => $tmp];
    }

    public function testThemeTemplateOverridesTheDefault(): void
    {
        $env = $this->configWithTheme('starter');

        $output = $this->renderer($env['config'])->render('greet', ['name' => 'Ada']);

        $this->assertSame('theme Ada', $output);
    }

    public function testUnprovidedTemplateFallsBackToDefaultDirectory(): void
    {
        $env = $this->configWithTheme('starter');

        $output = $this->renderer($env['config'])->render('only-default');

        $this->assertSame('only default', $output);
    }

    public function testNoThemeConfiguredBehavesAsBefore(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);
        file_put_contents($tmp . '/templates/greet.twig', 'default {{ name }}');

        $config = new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
            ],
        ]);

        $output = $this->renderer($config)->render('greet', ['name' => 'Ada']);

        $this->assertSame('default Ada', $output);
    }

    public function testMissingThemeDirectoryFallsBackToDefault(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);
        mkdir($tmp . '/themes', 0775, true);
        file_put_contents($tmp . '/templates/greet.twig', 'default {{ name }}');

        $config = new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
                'theme' => 'themes',
            ],
            'theme' => ['active' => 'no-such-theme'],
        ]);

        $output = $this->renderer($config)->render('greet', ['name' => 'Ada']);

        $this->assertSame('default Ada', $output);
    }
}
