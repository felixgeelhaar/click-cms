<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

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
        $html = $this->renderer->render([], '/home', 'TurboScience');

        $this->assertStringContainsString('<header class="cms-header">', $html);
        $this->assertStringContainsString('<a class="cms-brand" href="/">TurboScience</a>', $html);
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
}
