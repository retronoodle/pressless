<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;
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

    public function testRendersATemplateWithVariables(): void
    {
        $output = (new TwigRenderer($this->config()))->render('greet', ['name' => 'Ada']);

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

        $output = (new TwigRenderer($config))->render('raw', ['value' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testMissingTemplateBecomesASafeException(): void
    {
        $renderer = new TwigRenderer($this->config());

        try {
            $renderer->render('does-not-exist');
            $this->fail('Expected SafeException for missing template.');
        } catch (SafeException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('Could not render template', $e->getMessage());
        }
    }
}