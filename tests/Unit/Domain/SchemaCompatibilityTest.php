<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Schema\SchemaCompatibility;
use Click\Cms\Domain\Schema\SectionType;
use PHPUnit\Framework\TestCase;

/**
 * The pure decision behind restore re-validation: given the section types a site
 * declares today and a document from history, what no longer fits?
 *
 * No I/O here on purpose — schema plus content is all the question needs, which
 * is why it lives in the domain rather than in the service that reads them off
 * disk.
 */
final class SchemaCompatibilityTest extends TestCase
{
    private function hero(): SectionType
    {
        return SectionType::fromArray([
            'id' => 'hero',
            'label' => 'Hero',
            'fields' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                ['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
            ],
        ]);
    }

    public function testContentThatStillFitsProducesNoWarnings(): void
    {
        $check = new SchemaCompatibility([$this->hero()]);

        $report = $check->check([
            'sections' => [
                ['type' => 'hero', 'values' => ['heading' => 'Hi', 'body' => 'There']],
            ],
        ]);

        $this->assertTrue($report->fits());
        $this->assertSame([], $report->removedSectionTypes);
        $this->assertSame([], $report->strippedFields);
    }

    public function testASectionWhoseTypeIsNoLongerDeclaredIsReported(): void
    {
        // The site no longer offers a "hero" design at all.
        $check = new SchemaCompatibility([]);

        $report = $check->check([
            'sections' => [
                ['type' => 'hero', 'values' => ['heading' => 'Hi']],
            ],
        ]);

        $this->assertFalse($report->fits());
        $this->assertSame([['index' => 0, 'type' => 'hero']], $report->removedSectionTypes);
        // A section with no surviving type cannot also report stripped fields:
        // there is no schema left to strip against.
        $this->assertSame([], $report->strippedFields);
    }

    public function testAFieldTheSchemaNoLongerDeclaresIsReportedAsStripped(): void
    {
        $check = new SchemaCompatibility([$this->hero()]);

        $report = $check->check([
            'sections' => [
                ['type' => 'hero', 'values' => [
                    'heading' => 'Hi',
                    'subtitle' => 'Gone from the design',
                ]],
            ],
        ]);

        $this->assertFalse($report->fits());
        $this->assertSame([], $report->removedSectionTypes);
        $this->assertSame(
            [['index' => 0, 'type' => 'hero', 'field' => 'subtitle']],
            $report->strippedFields
        );
    }

    public function testWarningsCarryTheSectionIndexSoTheEditorCanFindIt(): void
    {
        $check = new SchemaCompatibility([$this->hero()]);

        $report = $check->check([
            'sections' => [
                ['type' => 'hero', 'values' => ['heading' => 'Ok']],
                ['type' => 'banner', 'values' => ['text' => 'x']],
                ['type' => 'hero', 'values' => ['heading' => 'Ok', 'legacy' => 'x']],
            ],
        ]);

        $this->assertSame([['index' => 1, 'type' => 'banner']], $report->removedSectionTypes);
        $this->assertSame(
            [['index' => 2, 'type' => 'hero', 'field' => 'legacy']],
            $report->strippedFields
        );
    }

    public function testADocumentWithNoSectionsFits(): void
    {
        $check = new SchemaCompatibility([$this->hero()]);

        $this->assertTrue($check->check(['title' => 'A plain page'])->fits());
        $this->assertTrue($check->check([])->fits());
    }

    public function testTheReportSerialisesToAStableShapeForTheApi(): void
    {
        $check = new SchemaCompatibility([$this->hero()]);

        $report = $check->check([
            'sections' => [
                ['type' => 'banner', 'values' => []],
                ['type' => 'hero', 'values' => ['heading' => 'Ok', 'legacy' => 'x']],
            ],
        ]);

        $this->assertSame(
            [
                'fits' => false,
                'removedSectionTypes' => [['index' => 0, 'type' => 'banner']],
                'strippedFields' => [['index' => 1, 'type' => 'hero', 'field' => 'legacy']],
            ],
            $report->toArray()
        );
    }

    public function testMalformedSectionsAreIgnoredRatherThanReported(): void
    {
        $check = new SchemaCompatibility([$this->hero()]);

        $report = $check->check([
            'sections' => [
                'not-an-array',
                ['no' => 'type here'],
                ['type' => '', 'values' => []],
            ],
        ]);

        $this->assertTrue($report->fits());
    }
}
