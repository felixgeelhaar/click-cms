<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

/**
 * `labelField` inside a repeater row.
 *
 * It was honoured at section level and not inside a row, and the difference was
 * visible in shipped content: a card's link rendered as
 * `<a href="/tables">/tables</a>`, offering a visitor the raw path as the words
 * to click. Any paired label printed underneath as a stray sentence.
 *
 * The section-level behaviour is deliberately *not* changed — there, an address
 * is a defensible last resort. Inside a row it is not, because a row always has
 * a title.
 */
final class RepeaterLabelFieldTest extends TestCase
{
    private SectionRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SectionRenderer(
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );
    }

    /** @param list<array<string, mixed>> $cards */
    private function cardGrid(array $cards): string
    {
        return $this->renderer->render(Content::create(
            ContentKey::page('home'),
            ['title' => 'Home', 'sections' => [['type' => 'card-grid', 'values' => ['cards' => $cards]]]]
        ));
    }

    public function testACardLinkReadsAsTheCardRatherThanAsItsAddress(): void
    {
        $html = $this->cardGrid([['title' => 'Tables', 'body' => 'Solid tops.', 'link' => '/tables']]);

        $this->assertStringContainsString('<a href="/tables" rel="noopener noreferrer">Tables</a>', $html);
        // The defect, stated as the thing that must not come back.
        $this->assertStringNotContainsString('>/tables<', $html);
    }

    /** A row with no title has nothing better to offer, so the address stands. */
    public function testALinkInARowWithNoTitleStillRendersSomething(): void
    {
        $html = $this->cardGrid([['body' => 'No title here.', 'link' => 'https://example.com/x']]);

        $this->assertStringContainsString('href="https://example.com/x"', $html);
        $this->assertStringContainsString('>https://example.com/x<', $html);
    }

    public function testLinkTextFromARowIsEscaped(): void
    {
        $html = $this->cardGrid([[
            'title' => '<script>alert(1)</script>',
            'link' => '/x',
        ]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The gallery deliberately keeps its caption separate from the picture's
     * description — they are different sentences — so nothing may consume it.
     */
    public function testAGalleryCaptionIsStillPrintedAndNotSwallowed(): void
    {
        $html = $this->renderer->render(Content::create(
            ContentKey::page('home'),
            ['title' => 'Home', 'sections' => [['type' => 'gallery', 'values' => ['images' => [
                ['image' => 'pic-00000000', 'caption' => 'Oak bench, 1.8 metres.'],
            ]]]]]
        ));

        $this->assertStringContainsString('Oak bench, 1.8 metres.', $html);
    }

    /**
     * A logo row names the mark in `title`, which is also the alt fallback. It
     * must still render as the heading — consuming it would leave a row of
     * pictures with no names.
     */
    public function testALogoNameIsStillPrinted(): void
    {
        $html = $this->renderer->render(Content::create(
            ContentKey::page('home'),
            ['title' => 'Home', 'sections' => [['type' => 'logos', 'values' => ['logos' => [
                ['logo' => 'mark-00000000', 'title' => 'Guild of Master Craftsmen'],
            ]]]]]
        ));

        $this->assertStringContainsString('Guild of Master Craftsmen</h3>', $html);
        $this->assertStringContainsString('alt="Guild of Master Craftsmen"', $html);
    }

    /**
     * The rule that took two attempts. Consumption is decided per row, so a mark
     * with a link shows its name as the link and a mark without one still shows
     * it as a heading. Deciding it from the field definitions instead deleted the
     * name from every unlinked mark, which is how this test came to exist.
     */
    public function testOnlyTheRowThatHasTheLinkGivesUpItsHeading(): void
    {
        $html = $this->renderer->render(Content::create(
            ContentKey::page('home'),
            ['title' => 'Home', 'sections' => [['type' => 'logos', 'values' => ['logos' => [
                ['logo' => 'a-00000000', 'title' => 'Linked Mark', 'link' => 'https://example.com/a'],
                ['logo' => 'b-11111111', 'title' => 'Unlinked Mark'],
            ]]]]]
        ));

        // The linked one becomes the link's wording, and appears once.
        $this->assertStringContainsString('>Linked Mark</a>', $html);
        $this->assertStringNotContainsString('Linked Mark</h3>', $html);

        // The unlinked one keeps its heading.
        $this->assertStringContainsString('Unlinked Mark</h3>', $html);
    }

    /**
     * Section level is unchanged: a call to action with no button label still
     * falls back to the address, which an existing test also asserts.
     */
    public function testSectionLevelFallbackIsUnchanged(): void
    {
        $html = $this->renderer->render(Content::create(
            ContentKey::page('home'),
            ['title' => 'Home', 'sections' => [['type' => 'call-to-action', 'values' => [
                'heading' => 'Talk to us',
                'buttonLabel' => 'Request a quote',
                'buttonUrl' => 'https://example.com/contact',
            ]]]]
        ));

        $this->assertStringContainsString('>Request a quote</a>', $html);
        $this->assertSame(1, substr_count($html, 'Request a quote'));
    }
}
