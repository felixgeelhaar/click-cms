<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\BasePath;
use Click\Cms\Http\NavigationRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The public site header, tested one rule at a time: what shows, what is marked
 * current, how a submenu is built, and that nothing an editor typed escapes as
 * markup.
 */
final class NavigationRendererTest extends TestCase
{
    private NavigationRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new NavigationRenderer();
    }

    /** @param array<int, array<string, mixed>> $children */
    private function item(string $label, string $href, bool $external = false, array $children = []): array
    {
        $out = ['label' => $label, 'href' => $href, 'external' => $external];
        if ($children !== []) {
            $out['children'] = $children;
        }
        return $out;
    }

    public function testNoItemsAndNoBrandRendersNothing(): void
    {
        $this->assertSame('', $this->renderer->render([], '/home', null));
        $this->assertSame('', $this->renderer->render([], '/home', '   '));
    }

    public function testBrandAloneRendersAHeaderWithNoNav(): void
    {
        $html = $this->renderer->render([], '/home', 'Acme Studio');

        $this->assertStringContainsString('<header class="cms-header">', $html);
        $this->assertStringContainsString('<a class="cms-brand" href="/">Acme Studio</a>', $html);
        // No menu, so no nav and no enhancement script.
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testItemsRenderAsAccessibleNavWithAMobileToggle(): void
    {
        $html = $this->renderer->render(
            [$this->item('Home', '/home'), $this->item('About', '/about')],
            '/home',
            null
        );

        $this->assertStringContainsString('<nav class="cms-nav" aria-label="Main">', $html);
        $this->assertStringContainsString('id="cms-nav-menu"', $html);
        // The toggle controls the menu it names.
        $this->assertStringContainsString('class="cms-nav-toggle" aria-expanded="false" aria-controls="cms-nav-menu"', $html);
        $this->assertStringContainsString('<a href="/home"', $html);
        $this->assertStringContainsString('<a href="/about"', $html);
    }

    public function testTheCurrentPageIsMarked(): void
    {
        $html = $this->renderer->render(
            [$this->item('Home', '/home'), $this->item('About', '/about')],
            '/about',
            null
        );

        // Only the matching item is current, for assistive tech and styling both.
        $this->assertStringContainsString('<a href="/about" aria-current="page">About</a>', $html);
        $this->assertStringContainsString('cms-nav-item--current', $html);
        $this->assertStringContainsString('<a href="/home">Home</a>', $html);
        // Exactly one item is current.
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testAnExternalItemOpensSafelyAndIsNeverCurrent(): void
    {
        $html = $this->renderer->render(
            [$this->item('Blog', 'https://example.com', external: true)],
            'https://example.com',
            null
        );

        $this->assertStringContainsString('rel="noopener noreferrer" target="_blank"', $html);
        // Even when its href equals the current one, an external link is not "the page".
        $this->assertStringNotContainsString('aria-current', $html);
    }

    public function testAParentRendersASubmenuDisclosureAndNestedList(): void
    {
        $html = $this->renderer->render(
            [
                $this->item('Services', '/services', children: [
                    $this->item('Consulting', '/services/consulting'),
                    $this->item('Training', '/services/training'),
                ]),
            ],
            '/services/training',
            null
        );

        $this->assertStringContainsString('cms-nav-item--has-children', $html);
        // The link to the parent page survives; a separate button opens the submenu.
        $this->assertStringContainsString('<a href="/services">Services</a>', $html);
        $this->assertStringContainsString('class="cms-nav-subtoggle" aria-expanded="false"', $html);
        $this->assertStringContainsString('Submenu for Services', $html);
        $this->assertStringContainsString('<ul class="cms-nav-children">', $html);
        // Active marking reaches into children.
        $this->assertStringContainsString('<a href="/services/training" aria-current="page">Training</a>', $html);
    }

    public function testEditorTextIsEscapedInLabelsAndBrand(): void
    {
        $html = $this->renderer->render(
            [$this->item('<b>News</b>', '/news')],
            '/home',
            'A & B "Labs"'
        );

        $this->assertStringNotContainsString('<b>News</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;News&lt;/b&gt;', $html);
        $this->assertStringContainsString('A &amp; B &quot;Labs&quot;', $html);
    }

    public function testTheEnhancementScriptIsIncludedWhenThereIsANav(): void
    {
        $html = $this->renderer->render([$this->item('Home', '/home')], '/home', null);

        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString("classList.add('cms-js')", $html);
        $this->assertStringContainsString('cms-nav-open', $html);
    }

    /* ------------------------------------------------ under a URL prefix -- */

    public function testOnSiteLinksCarryTheInstallationsPrefix(): void
    {
        $renderer = new NavigationRenderer(BasePath::detect([], '/2026/cms'));

        $html = $renderer->render([$this->item('News', '/news')], '/home', 'Acme');

        $this->assertStringContainsString('<a href="/2026/cms/news"', $html);
        $this->assertStringContainsString('<a class="cms-brand" href="/2026/cms/"', $html);
    }

    /** An external link names its own host; prefixing it would corrupt it. */
    public function testAnExternalLinkIsLeftAlone(): void
    {
        $renderer = new NavigationRenderer(BasePath::detect([], '/2026/cms'));

        $html = $renderer->render([$this->item('Docs', 'https://example.com/docs', true)], '/home', null);

        $this->assertStringContainsString('href="https://example.com/docs"', $html);
    }

    /**
     * The current-page mark survives the prefix. Menu hrefs and the current href
     * are both site paths, so they are compared before either is prefixed — the
     * alternative is a nav in which nothing is ever marked current.
     */
    public function testTheCurrentItemIsStillMarkedUnderAPrefix(): void
    {
        $renderer = new NavigationRenderer(BasePath::detect([], '/2026/cms'));

        $html = $renderer->render([$this->item('Home', '/home')], '/home', null);

        $this->assertStringContainsString('<a href="/2026/cms/home" aria-current="page">Home</a>', $html);
        $this->assertStringContainsString('cms-nav-item--current', $html);
    }
}
