<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

/**
 * A flat list of lines, and a per-row presentation flag.
 *
 * Both existed to close the same shape of gap. A repeater cannot nest, so a
 * plan inside a `pricing` repeater could not hold a repeating list: "what's
 * included" had to be a textarea, which renders as one paragraph of
 * `<br>`-separated lines — a list to look at and not a list to anything reading
 * the document. And only a *top-level* select became a modifier class, so a
 * row-level flag like "most popular" printed the literal word under the plan
 * instead of marking it.
 */
final class StringListFieldTest extends TestCase
{
    private SectionRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SectionRenderer(
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );
    }

    /** @param array<string, mixed> $values */
    private function pricing(array $values): string
    {
        return $this->renderer->render(Content::create(
            ContentKey::page('p'),
            ['title' => 'P', 'sections' => [['type' => 'pricing', 'values' => $values]]]
        ));
    }

    /* ------------------------------------------------------------- the list -- */

    public function testWhatIsIncludedIsARealListInsideARepeaterRow(): void
    {
        $html = $this->pricing(['plans' => [[
            'title' => 'Repair',
            'features' => ['Collection and return', 'A written report'],
        ]]]);

        $this->assertStringContainsString('<ul class="cms-field cms-field--features cms-lines">', $html);
        $this->assertStringContainsString('<li>Collection and return</li>', $html);
        $this->assertStringContainsString('<li>A written report</li>', $html);
        // The thing it replaced.
        $this->assertStringNotContainsString('<br', $html);
    }

    public function testLinesAreEscaped(): void
    {
        $html = $this->pricing(['plans' => [[
            'title' => 'X', 'features' => ['<script>alert(1)</script>'],
        ]]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAnEmptyListRendersNothingRatherThanAnEmptyElement(): void
    {
        $html = $this->pricing(['plans' => [['title' => 'X', 'features' => []]]]);

        $this->assertStringNotContainsString('cms-lines', $html);
    }

    public function testBlankLinesAreDroppedNotRenderedAsGaps(): void
    {
        $html = $this->pricing(['plans' => [[
            'title' => 'X', 'features' => ['One', '', '   ', 'Two'],
        ]]]);

        $this->assertSame(2, substr_count($html, '<li>'));
    }

    /* ----------------------------------------------------- what is stored -- */

    /**
     * The validator accepts a textarea's worth of lines as well as an array,
     * because that is what an editor pastes in and what this data was before the
     * field type existed.
     */
    public function testPastedTextBecomesAList(): void
    {
        $type = SectionType::fromArray([
            'id' => 't', 'label' => 'T',
            'fields' => [['name' => 'items', 'type' => 'list', 'label' => 'Items']],
        ]);

        $result = (new SectionValidator())->validate($type, ['items' => "One\nTwo\n\n  Three  "]);

        $this->assertTrue($result->isValid());
        $this->assertSame(['One', 'Two', 'Three'], $result->values['items']);
    }

    public function testSomethingThatIsNotAListIsRefusedClearly(): void
    {
        $type = SectionType::fromArray([
            'id' => 't', 'label' => 'T',
            'fields' => [['name' => 'items', 'type' => 'list', 'label' => 'Items']],
        ]);

        $result = (new SectionValidator())->validate($type, ['items' => 42]);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('list of lines', $result->errors['items']);
    }

    /* --------------------------------------------------- the per-row flag -- */

    public function testAFeaturedPlanIsMarkedOnItsRowRatherThanPrinted(): void
    {
        $html = $this->pricing(['plans' => [
            ['title' => 'Repair', 'emphasis' => 'normal'],
            ['title' => 'Commission', 'emphasis' => 'featured'],
        ]]);

        $this->assertStringContainsString('<li class="cms-item cms-item--emphasis-normal">', $html);
        $this->assertStringContainsString('<li class="cms-item cms-item--emphasis-featured">', $html);
        // The defect: the choice appearing as a word on the page.
        $this->assertStringNotContainsString('>featured<', $html);
    }

    /** A section-level select still marks the section, unchanged. */
    public function testASectionLevelSelectStillMarksTheSection(): void
    {
        $html = $this->pricing(['columns' => '3', 'plans' => [['title' => 'X']]]);

        $this->assertStringContainsString('cms-section--columns-3', $html);
    }
}
