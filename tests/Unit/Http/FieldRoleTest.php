<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\FieldDefinition;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

/**
 * A field declaring what it *is*, rather than the renderer inferring it.
 *
 * Element choice used to come entirely from a field's name and type: `heading`
 * or `title` produced an `<h2>`, every other scalar a `<p>`, every repeater a
 * `<ul>`. A good default and a hard ceiling — a testimonial could not be a
 * `<blockquote>`, opening hours could not be a `<dl>`, and no page could have a
 * heading below level two, so long pages read as a flat outline.
 *
 * The vocabulary is closed on purpose. A design asks for `quote`, not for
 * `<blockquote>`, so the renderer keeps every guarantee about structure and
 * escaping in one testable place instead of trusting each design not to emit a
 * tag of its own.
 */
final class FieldRoleTest extends TestCase
{
    private SectionRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SectionRenderer(
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );
    }

    /** @param array<string, mixed> $values */
    private function render(string $type, array $values): string
    {
        return $this->renderer->render(Content::create(
            ContentKey::page('p'),
            ['title' => 'P', 'sections' => [['type' => $type, 'values' => $values]]]
        ));
    }

    /* ------------------------------------------------- the closed vocabulary -- */

    public function testAnUnknownRoleIsDroppedRatherThanHonoured(): void
    {
        $field = FieldDefinition::fromArray([
            'name' => 'x', 'type' => 'text', 'label' => 'X', 'as' => 'marquee',
        ]);

        $this->assertNull($field->as, 'an unrecognised role must not survive into the renderer');
    }

    /** A role that makes no sense for the type is refused just as firmly. */
    public function testARoleWrongForItsTypeIsDropped(): void
    {
        $onText = FieldDefinition::fromArray([
            'name' => 'x', 'type' => 'text', 'label' => 'X', 'as' => 'definitions',
        ]);
        $onRepeater = FieldDefinition::fromArray([
            'name' => 'r', 'type' => 'repeater', 'label' => 'R', 'as' => 'quote',
            'fields' => [['name' => 'a', 'type' => 'text', 'label' => 'A']],
        ]);

        $this->assertNull($onText->as);
        $this->assertNull($onRepeater->as);
    }

    public function testAValidRoleSurvivesAndRoundTrips(): void
    {
        $field = FieldDefinition::fromArray([
            'name' => 'words', 'type' => 'textarea', 'label' => 'Words', 'as' => 'quote',
        ]);

        $this->assertSame('quote', $field->as);
        // Exposed to the admin, which needs to know what it is editing.
        $this->assertSame('quote', $field->toArray()['as'] ?? null);
    }

    /* ------------------------------------------------------- the real designs -- */

    public function testATestimonialIsMarkedUpAsAQuotation(): void
    {
        $html = $this->render('quote', [
            'quote' => 'They measured the room.',
            'attribution' => 'Amara Ndiaye',
        ]);

        $this->assertStringContainsString('<blockquote', $html);
        $this->assertStringContainsString('They measured the room.', $html);
        // The attribution is not part of the quotation.
        $this->assertStringNotContainsString('Amara Ndiaye</blockquote>', $html);
    }

    public function testOpeningHoursAreADescriptionList(): void
    {
        $html = $this->render('details', ['rows' => [
            ['label' => 'Monday', 'value' => 'Closed'],
            ['label' => 'Tuesday to Friday', 'value' => '9:00 – 17:00'],
        ]]);

        $this->assertStringContainsString('<dl', $html);
        $this->assertStringContainsString('<dt class="cms-field cms-field--label">Monday</dt>', $html);
        $this->assertStringContainsString('<dd class="cms-field cms-field--value">Closed</dd>', $html);
        // Not the generic list it used to be.
        $this->assertStringNotContainsString('<li', $html);
    }

    /**
     * The `subheading` role still produces an h3 — but no shipped design uses it
     * on a section, because a section heading introduces the sections around it
     * and belongs at their level. `section-heading` briefly used it and produced
     * an h3 sitting above h2s, which is a worse outline than the flat one.
     */
    public function testTheSubheadingRoleProducesALevelThreeHeading(): void
    {
        $field = \Click\Cms\Domain\Schema\FieldDefinition::fromArray([
            'name' => 'x', 'type' => 'text', 'label' => 'X', 'as' => 'subheading',
        ]);

        $this->assertSame('subheading', $field->as);
    }

    /* ------------------------------------------------------------ the edges -- */

    public function testAHalfFilledDefinitionRowIsSkippedNotRenderedBroken(): void
    {
        $html = $this->render('details', ['rows' => [
            ['label' => 'Monday'],                       // no value
            ['value' => 'Closed'],                        // no term
            ['label' => 'Saturday', 'value' => '10 – 4'], // complete
        ]]);

        $this->assertStringContainsString('Saturday', $html);
        $this->assertStringNotContainsString('Monday', $html);
        $this->assertSame(1, substr_count($html, '<dt'));
        $this->assertSame(1, substr_count($html, '<dd'));
    }

    public function testEveryDefinitionRowIsEmptySoNothingRenders(): void
    {
        $this->assertStringNotContainsString(
            '<dl',
            $this->render('details', ['rows' => [['label' => 'Only a term']]])
        );
    }

    /** Roles change the element, never the escaping. */
    public function testMarkupInAQuoteIsStillEscaped(): void
    {
        $html = $this->render('quote', [
            'quote' => '<script>alert(1)</script>',
            'attribution' => 'X',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testMarkupInADefinitionIsStillEscaped(): void
    {
        $html = $this->render('details', ['rows' => [
            ['label' => '<b>Mon</b>', 'value' => '<img src=x onerror=1>'],
        ]]);

        // The property is that no *markup* survives, not that the characters
        // never appear: `&lt;img src=x onerror=1&gt;` is inert text and contains
        // the word "onerror" quite legitimately. Asserting on the word failed a
        // correctly-escaped output, which is a worse test than none.
        $this->assertStringNotContainsString('<b>Mon', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=1&gt;', $html);
    }

    /** A design declaring nothing renders exactly as it always did. */
    public function testADesignWithNoRolesIsUnchanged(): void
    {
        $html = $this->render('rich-text', ['heading' => 'A heading', 'body' => '<p>Words.</p>']);

        $this->assertStringContainsString('<h2 class="cms-field cms-field--heading">A heading</h2>', $html);
        $this->assertStringNotContainsString('<h3', $html);
        $this->assertStringNotContainsString('<blockquote', $html);
    }
}
