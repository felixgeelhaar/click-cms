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
     * A plain text field is written by trusted editors, but "trusted" describes
     * intent, not what a paste from elsewhere contains, so it is escaped whole.
     * A rich-text field is HTML on purpose, so it is sanitised to an allowlist
     * rather than escaped — but the outcome for an injected script or handler is
     * the same: nothing hostile becomes live markup.
     */
    public function testEveryValueIsEscapedOrSanitised(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => [
                // A plain text field: escaped, so the characters survive as text.
                'heading' => '<script>alert(1)</script>',
                // A rich-text field: sanitised, so the disallowed <img> and its
                // handler are stripped out entirely.
                'body' => '<img src=x onerror=alert(1)>',
            ]],
        ]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        // The rich-text handler stripped the disallowed image outright.
        $this->assertStringNotContainsString('onerror', $html);
        // The escaped heading text still shows the characters the editor typed.
        // "alert(1)" surviving here is inert text inside &lt;script&gt;, not a
        // live tag — which is exactly the escaping this asserts.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /**
     * Rich text is HTML by design: an editor's bold, links and lists must reach
     * the page as markup, not as escaped angle brackets a reader would see.
     */
    public function testRichTextRendersAllowedFormattingAsHtml(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => [
                'body' => '<p>Hello <strong>world</strong> — <a href="https://example.com">link</a>.</p>',
            ]],
        ]));

        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringContainsString(
            '<a href="https://example.com" rel="noopener noreferrer">link</a>',
            $html
        );
        // Emphatically not escaped into visible tags.
        $this->assertStringNotContainsString('&lt;strong&gt;', $html);
    }

    /**
     * The same value is an XSS surface, because it is emitted as markup. A
     * script, a handler or a javascript: link in it must not survive, while the
     * surrounding safe prose does.
     */
    public function testRichTextIsSanitisedNotTrusted(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'rich-text', 'values' => [
                'body' => '<p>ok</p><script>alert(1)</script>'
                    . '<p onclick="steal()">two</p>'
                    . '<a href="javascript:alert(1)">x</a>',
            ]],
        ]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<p>ok</p>', $html);
        $this->assertStringContainsString('<p>two</p>', $html);
    }

    /**
     * A textarea is not rich text: it stays plain prose, escaped exactly as
     * before, so the sanitiser must not have loosened it into an HTML field.
     */
    public function testTextareaRemainsEscapedPlainProse(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'call-to-action', 'values' => [
                'body' => '<strong>not bold</strong>',
                'buttonLabel' => 'Go',
                'buttonUrl' => 'https://example.com',
            ]],
        ]));

        $this->assertStringNotContainsString('<strong>not bold</strong>', $html);
        $this->assertStringContainsString('&lt;strong&gt;not bold&lt;/strong&gt;', $html);
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

    /**
     * A link and its wording are one control to a reader. Rendering them as two
     * fields put the raw address on the page beside a separate label.
     */
    public function testALinkUsesItsDeclaredLabelAsTheLinkText(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'call-to-action', 'values' => [
                'heading' => 'Talk to us',
                'buttonLabel' => 'Request a quote',
                'buttonUrl' => 'https://example.com/contact',
            ]],
        ]));

        $this->assertStringContainsString(
            '<a href="https://example.com/contact" rel="noopener noreferrer">Request a quote</a>',
            $html
        );
        // The address must not appear as visible wording, and the label must not
        // also be printed on its own.
        $this->assertStringNotContainsString('>https://example.com/contact<', $html);
        $this->assertSame(1, substr_count($html, 'Request a quote'));
    }

    /**
     * The label field may be empty even when the link is not, so the link still
     * has to render something rather than becoming unclickable text.
     */
    public function testALinkWithoutItsLabelFallsBackToTheAddress(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'call-to-action', 'values' => [
                'heading' => 'Talk to us',
                'buttonUrl' => 'https://example.com/contact',
            ]],
        ]));

        $this->assertStringContainsString(
            '<a href="https://example.com/contact" rel="noopener noreferrer">https://example.com/contact</a>',
            $html
        );
    }

    public function testLinkTextIsEscaped(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'call-to-action', 'values' => [
                'buttonLabel' => '<script>alert(1)</script>',
                'buttonUrl' => 'https://example.com',
            ]],
        ]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
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

    /**
     * Without a media service nothing resolves, so a card's title is all the
     * renderer has to describe the picture with.
     */
    public function testACardTitleDescribesAnUnresolvableImage(): void
    {
        $html = $this->renderer->render($this->page([
            ['type' => 'card-grid', 'values' => ['cards' => [
                ['title' => 'Balancing', 'image' => 'some-reference'],
            ]]],
        ]));

        $this->assertStringContainsString('alt="Balancing"', $html);
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
