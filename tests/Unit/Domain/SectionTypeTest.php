<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Schema\FieldType;
use Click\Cms\Domain\Schema\SectionType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SectionTypeTest extends TestCase
{
    public function testParsesADeclaredSectionType(): void
    {
        $type = SectionType::fromArray([
            'id' => 'feature-grid',
            'label' => 'Feature Grid',
            'description' => 'A row of features',
            'icon' => 'grid',
            'fields' => [
                ['name' => 'heading', 'type' => 'text', 'required' => true],
                ['name' => 'body', 'type' => 'richtext'],
            ],
        ]);

        $this->assertSame('feature-grid', $type->id);
        $this->assertSame('Feature Grid', $type->label);
        $this->assertSame(['heading', 'body'], $type->fieldNames());
        $this->assertSame(FieldType::RichText, $type->field('body')?->type);
    }

    public function testLabelDefaultsToIdAndFieldLabelIsHumanised(): void
    {
        $type = SectionType::fromArray([
            'id' => 'banner',
            'fields' => [['name' => 'call_to_action', 'type' => 'text']],
        ]);

        $this->assertSame('banner', $type->label);
        $this->assertSame('Call to action', $type->field('call_to_action')?->label);
    }

    public function testRejectsMissingOrMalformedId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray(['fields' => [['name' => 'a', 'type' => 'text']]]);
    }

    public function testRejectsNonSlugId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'Feature Grid',
            'fields' => [['name' => 'a', 'type' => 'text']],
        ]);
    }

    public function testRejectsSectionWithoutFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray(['id' => 'empty', 'fields' => []]);
    }

    public function testRejectsDuplicateFieldNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'dupe',
            'fields' => [
                ['name' => 'a', 'type' => 'text'],
                ['name' => 'a', 'type' => 'number'],
            ],
        ]);
    }

    public function testRejectsUnknownFieldType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'x',
            'fields' => [['name' => 'a', 'type' => 'hologram']],
        ]);
    }

    public function testRejectsFieldNameThatIsNotAValidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'x',
            'fields' => [['name' => '2cool', 'type' => 'text']],
        ]);
    }

    public function testSelectMustDeclareOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'x',
            'fields' => [['name' => 'align', 'type' => 'select']],
        ]);
    }

    public function testRepeaterMustDeclareSubFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'x',
            'fields' => [['name' => 'rows', 'type' => 'repeater']],
        ]);
    }

    /**
     * Nested repeaters produce an editing experience a non-technical person
     * cannot follow, so the schema refuses them outright.
     */
    public function testRepeaterMayNotNestAnotherRepeater(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SectionType::fromArray([
            'id' => 'x',
            'fields' => [[
                'name' => 'rows',
                'type' => 'repeater',
                'fields' => [[
                    'name' => 'inner',
                    'type' => 'repeater',
                    'fields' => [['name' => 'a', 'type' => 'text']],
                ]],
            ]],
        ]);
    }

    public function testRoundTripsThroughToArray(): void
    {
        $spec = [
            'id' => 'promo',
            'label' => 'Promo',
            'fields' => [
                ['name' => 'heading', 'type' => 'text', 'required' => true],
                [
                    'name' => 'rows',
                    'type' => 'repeater',
                    'label' => 'Rows',
                    'min' => 1,
                    'fields' => [['name' => 'title', 'type' => 'text', 'required' => true]],
                ],
            ],
        ];

        $restored = SectionType::fromArray(SectionType::fromArray($spec)->toArray());

        $this->assertSame('promo', $restored->id);
        $this->assertSame(['heading', 'rows'], $restored->fieldNames());
        $this->assertSame('title', $restored->field('rows')?->fields[0]->name);
    }
}
