<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Exception\SafeException;
use Stead\Themes\ActiveThemeResolver;
use Stead\Themes\ThemeManifestReader;
use Stead\Themes\ThemeRepository;
use Stead\Themes\ThemeSettingsRepository;
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

    public function testThemeSettingsGlobalIsHtmlEscapedByDefault(): void
    {
        $env = $this->writerEnv([
            ['key' => 'hero', 'type' => 'text', 'default' => ''],
        ]);
        $env['repo']->save('starter', ['hero' => '<script>alert(1)</script>']);
        file_put_contents(
            $env['themeDir'] . '/render.twig',
            '{{ theme_settings.hero }}',
        );

        $output = $env['renderer']->render('render');

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testThemeSettingsGlobalFallsBackToManifestDefaultWhenUnset(): void
    {
        $env = $this->writerEnv([
            ['key' => 'hero', 'type' => 'text', 'default' => 'Hello world'],
        ]);
        file_put_contents($env['themeDir'] . '/render.twig', '{{ theme_settings.hero }}');

        $output = $env['renderer']->render('render');

        $this->assertStringContainsString('Hello world', $output);
    }

    public function testThemeSettingsGlobalIsEmptyWhenNoActiveTheme(): void
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);
        mkdir($tmp . '/themes', 0775, true);
        file_put_contents($tmp . '/templates/render.twig', '[{{ theme_settings.hero|default("") }}]');

        $config = new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
                'theme' => 'themes',
            ],
            'theme' => ['active' => 'no-such-theme'],
        ]);

        $dbConfig = new Configuration($tmp, 'test', [
            'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
            'paths' => ['migrations' => 'database/migrations'],
        ]);
        mkdir($tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $tmp . '/database/migrations/' . basename($src));
        }
        $connection = new Connection($dbConfig);
        (new Migrator($connection, $dbConfig))->migrate();
        $themeRepository = new ThemeRepository($connection);
        $renderer = new TwigRenderer(
            $config,
            new ActiveThemeResolver($themeRepository, $config),
            $themeRepository,
            new ThemeManifestReader(),
            new ThemeSettingsRepository($connection),
        );

        $output = $renderer->render('render');

        $this->assertSame('[]', $output);
        $connection->close();
    }

    /**
     * Sets up an in-memory DB with a `starter` active theme row, on-disk
     * theme dir with the given settings schema, and a renderer wired
     * with all the dependencies needed to populate the global.
     *
     * @param list<array{key: string, type: string, default: string, label?: string, options?: list<string>}> $settings
     * @return array{config: Configuration, renderer: TwigRenderer, themeDir: string, repo: ThemeSettingsRepository}
     */
    private function writerEnv(array $settings = []): array
    {
        $tmp = sys_get_temp_dir() . '/stead-twig-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/templates', 0775, true);
        mkdir($tmp . '/var/cache', 0775, true);
        mkdir($tmp . '/themes/starter', 0775, true);
        mkdir($tmp . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $tmp . '/database/migrations/' . basename($src));
        }

        $config = new Configuration($tmp, 'test', [
            'paths' => [
                'templates' => 'templates',
                'cache' => 'var/cache',
                'theme' => 'themes',
                'migrations' => 'database/migrations',
            ],
            'theme' => ['active' => 'starter'],
            'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
        ]);

        $connection = new Connection($config);
        (new Migrator($connection, $config))->migrate();
        $themeRepository = new ThemeRepository($connection);
        if ($themeRepository->findActive()?->slug !== 'starter') {
            $themeRepository->create('starter', 'Starter');
        }

        if ($settings !== []) {
            $payload = ['name' => 'Starter', 'settings' => $settings];
            file_put_contents(
                $tmp . '/themes/starter/theme.json',
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
        }

        $repo = new ThemeSettingsRepository($connection);
        $renderer = new TwigRenderer(
            $config,
            new ActiveThemeResolver($themeRepository, $config),
            $themeRepository,
            new ThemeManifestReader(),
            $repo,
        );

        return [
            'config' => $config,
            'renderer' => $renderer,
            'themeDir' => $tmp . '/themes/starter',
            'repo' => $repo,
        ];
    }
}

