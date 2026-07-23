<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\PageShell;
use PHPUnit\Framework\TestCase;

/**
 * The shared page document chrome. Every rendering path — sections, the visual
 * builder — wraps its body in this, so a page is navigable and indexable however
 * its body was produced.
 */
final class PageShellTest extends TestCase
{
    private function shell(): PageShell
    {
        return new PageShell(
            'en',
            '<title>Home</title>',
            '<header class="cms-header">nav</header>',
        );
    }

    public function testItProducesAFullDocument(): void
    {
        $html = $this->shell()->render('<p>hello</p>');

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<meta name="viewport"', $html);
    }

    public function testItCarriesTheHeadTheHeaderAndTheTheme(): void
    {
        $html = $this->shell()->render('<p>hello</p>');

        $this->assertStringContainsString('<title>Home</title>', $html);
        $this->assertStringContainsString('<header class="cms-header">nav</header>', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/theme.css">', $html);
    }

    public function testTheBodyIsWrappedInMainAfterTheHeader(): void
    {
        $html = $this->shell()->render('<p>hello</p>');

        $this->assertStringContainsString('<main><p>hello</p></main>', $html);
        // Header before main, so navigation leads the document.
        $this->assertLessThan(
            strpos($html, '<main>'),
            strpos($html, 'cms-header'),
        );
    }

    public function testExtraHeadJoinsTheHeadAndDefaultsToNothing(): void
    {
        $plain = $this->shell()->render('<p>hi</p>');
        $this->assertStringNotContainsString('<style>', $plain);

        $withStyle = $this->shell()->render('<p>hi</p>', '<style>.x{color:red}</style>');
        $this->assertStringContainsString('<style>.x{color:red}</style>', $withStyle);
        // In the head, above the body.
        $this->assertLessThan(
            strpos($withStyle, '<body>'),
            strpos($withStyle, '<style>'),
        );
    }
}
