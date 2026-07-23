<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\PageShell;
use PHPUnit\Framework\TestCase;

/**
 * A page built with the visual builder must arrive through the same document
 * chrome as an ordinary page — nav, SEO head and theme — rather than as a bare
 * standalone document. The plugin does that by wrapping its node tree in the
 * {@see PageShell} core hands it through the render hook.
 */
final class VisualBuilderChromeTest extends TestCase
{
    private function plugin(): object
    {
        require_once dirname(__DIR__, 3) . '/plugins/visual-builder/bootstrap.php';

        $manager = new PluginManager(dirname(__DIR__, 3) . '/plugins');

        return new \Plugin_visual_builder($manager);
    }

    private function builderPage(): Content
    {
        return Content::create(ContentKey::page('landing'), [
            'title' => 'Landing',
            'builder' => [
                'root' => 'r',
                'nodes' => [
                    'r' => ['type' => 'section', 'children' => ['t']],
                    't' => ['type' => 'text', 'props' => ['text' => 'Hello world']],
                ],
            ],
        ]);
    }

    public function testABuilderPageIsWrappedInTheSharedShell(): void
    {
        $shell = new PageShell('en', '<title>Landing</title>', '<header class="cms-header">nav</header>');

        $html = $this->plugin()->hook_web_render([
            'page' => $this->builderPage(),
            'preview' => false,
            'shell' => $shell,
        ]);

        // The builder's own body is present...
        $this->assertStringContainsString('Hello world', $html);
        // ...inside the site chrome, not a bare document.
        $this->assertStringContainsString('<header class="cms-header">nav</header>', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/theme.css">', $html);
        $this->assertStringContainsString('<main>', $html);
    }

    public function testResponsiveStylesRideIntoTheHeadNotBeforeTheBody(): void
    {
        $shell = new PageShell('en', '<title>Landing</title>', '');

        $page = Content::create(ContentKey::page('landing'), [
            'title' => 'Landing',
            'builder' => [
                'root' => 'r',
                'breakpoints' => [['id' => 'md', 'minWidth' => 768]],
                'nodes' => [
                    'r' => [
                        'type' => 'grid',
                        'children' => [],
                        'responsive' => ['md' => ['props' => ['columns' => 3]]],
                    ],
                ],
            ],
        ]);

        $html = $this->plugin()->hook_web_render(['page' => $page, 'preview' => false, 'shell' => $shell]);

        $this->assertStringContainsString('@media (min-width:768px)', $html);
        // The media query sits in the head, above the body.
        $this->assertLessThan(strpos($html, '<body>'), strpos($html, '@media'));
    }

    public function testItIgnoresPagesWithoutABuilderTree(): void
    {
        $shell = new PageShell('en', '<title>Plain</title>', '');
        $page = Content::create(ContentKey::page('plain'), ['title' => 'Plain']);

        $this->assertNull($this->plugin()->hook_web_render([
            'page' => $page,
            'preview' => false,
            'shell' => $shell,
        ]));
    }

    public function testWithoutAShellItStillRendersAStandaloneDocument(): void
    {
        $html = $this->plugin()->hook_web_render([
            'page' => $this->builderPage(),
            'preview' => false,
        ]);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('Hello world', $html);
    }
}
