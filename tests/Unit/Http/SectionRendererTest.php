<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

final class SectionRendererTest extends TestCase
{
    private SectionRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SectionRenderer(
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function page(array $sections): Content
    {
        return Content::create(ContentKey::page('home'), ['title' => 'Home', 'sections' => $sections]);
    }

    public function testRendersEachSectionInOrder(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => ['heading' => 'First', 'body' => 'One']],
            ['type' => 'call-to-action', 'values' => [
                'heading' => 'Second', 'buttonLabel' => 'Go', 'buttonUrl' => 'https://example.com']],
        ]));

        $this->assertLessThan(strpos($html, 'Second'), strpos($html, 'First'));
        $this->assertStringContainsString('cms-section--rich-text', $html);
        $this->assertStringContainsString('cms-section--call-to-action', $html);
    }

    public function testAPageWithNoSectionsRendersNothing(): void
    {
        $this->assertSame('', $this->renderer->render($this->page([])));
        $this->assertSame('', $this->renderer->render(Content::create(ContentKey::page('x'))));
    }

    /**
     * A design that is no longer declared is skipped rather than guessed at.
     * The content stays in storage either way.
     */
    public function testAnUnknownSectionTypeIsSkipped(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'no-such-design', 'values' => ['heading' => 'Ghost']],
            ['type' => 'rich-text', 'values' => ['body' => 'Real']],
        ]));

        $this->assertStringNotContainsString('Ghost', $html);
        $this->assertStringContainsString('Real', $html);
    }

    /**
     * Content is written by trusted editors, but "trusted" describes intent,
     * not what a paste from elsewhere contains.
     */
    public function testEveryValueIsEscaped(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => [
                'heading' => '<script>alert(1)</script>',
                'body' => '<img src=x onerror=alert(1)>',
            ]],
        ]));

        // What matters is that neither becomes markup. The characters may
        // still appear, escaped, as the text the editor actually typed.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    /**
     * A select is a choice about presentation, not something to print. Printing
     * it put the bare words "wide" and "4" on the page.
     */
    public function testSelectFieldsBecomeClassesRatherThanText(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => ['body' => 'Text', 'width' => 'wide']],
        ]));

        $this->assertStringContainsString('cms-section--width-wide', $html);
        $this->assertStringNotContainsString('<p class="cms-field--width">wide</p>', $html);
    }

    public function testHeadingsBecomeHeadings(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => ['heading' => 'A Title', 'body' => 'Body']],
        ]));

        $this->assertStringContainsString('<h2 class="cms-field cms-field--heading">A Title</h2>', $html);
    }

    public function testRepeaterRowsBecomeListItems(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'facts', 'values' => ['heading' => 'Numbers', 'items' => [
                ['value' => '2013', 'caption' => 'Founded'],
                ['value' => '50+', 'caption' => 'Projects'],
            ]]],
        ]));

        $this->assertSame(2, substr_count($html, '<li class="cms-item">'));
        $this->assertStringContainsString('2013', $html);
        $this->assertStringContainsString('Projects', $html);
    }

    public function testAnEmptySectionProducesNoMarkup(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => []],
        ]));

        $this->assertSame('', $html);
    }

    /**
     * Without a media service to resolve variants, an image still renders —
     * just without a srcset — rather than disappearing.
     */
    public function testAnUnresolvableImageStillRenders(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'media-text', 'values' => ['image' => 'some-old-reference', 'body' => 'Text']],
        ]));

        $this->assertStringContainsString('<img src="/api/media/file/some-old-reference"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function testMalformedSectionsAreIgnoredRatherThanFatal(): void
    {
        $page = Content::create(ContentKey::page('home'), ['sections' => [
            'not an array',
            ['no type' => true],
            ['type' => 'rich-text', 'values' => 'not an array'],
            ['type' => 'rich-text', 'values' => ['body' => 'Survives']],
        ]]);

        $html = $this->renderer->render($page);

        $this->assertStringContainsString('Survives', $html);
    }
}
