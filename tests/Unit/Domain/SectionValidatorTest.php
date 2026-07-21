<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionValidator;
use PHPUnit\Framework\TestCase;

final class SectionValidatorTest extends TestCase
{
    private SectionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SectionValidator();
    }

    /**
     * A deliberately arbitrary section type: the CMS ships none of its own, so
     * the tests invent one exactly as a site would.
     *
     * @param list<array<string, mixed>> $fields
     */
    private function type(array $fields): SectionType
    {
        return SectionType::fromArray([
            'id' => 'example',
            'label' => 'Example',
            'fields' => $fields,
        ]);
    }

    public function testAcceptsValidInputAndNormalisesValues(): void
    {
        $type = $this->type([
            ['name' => 'heading', 'type' => 'text', 'required' => true],
            ['name' => 'weight', 'type' => 'number'],
            ['name' => 'featured', 'type' => 'boolean'],
        ]);

        $result = $this->validator->validate($type, [
            'heading' => 'Hello',
            'weight' => '42',
            'featured' => 1,
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame('Hello', $result->values['heading']);
        $this->assertSame(42, $result->values['weight']);
        $this->assertTrue($result->values['featured']);
    }

    public function testStripsFieldsTheSchemaDoesNotDeclare(): void
    {
        $type = $this->type([['name' => 'heading', 'type' => 'text']]);

        $result = $this->validator->validate($type, [
            'heading' => 'Hello',
            'injected' => 'should not be stored',
        ]);

        $this->assertTrue($result->isValid());
        $this->assertArrayNotHasKey('injected', $result->values);
    }

    public function testReportsEveryErrorAtOnce(): void
    {
        $type = $this->type([
            ['name' => 'a', 'type' => 'text', 'required' => true],
            ['name' => 'b', 'type' => 'text', 'required' => true],
            ['name' => 'c', 'type' => 'number'],
        ]);

        $result = $this->validator->validate($type, ['c' => 'not a number']);

        $this->assertFalse($result->isValid());
        $this->assertCount(3, $result->errors);
    }

    public function testRequiredFieldRejectsEmptyStringAndWhitespace(): void
    {
        $type = $this->type([['name' => 'heading', 'type' => 'text', 'required' => true]]);

        $this->assertFalse($this->validator->validate($type, ['heading' => ''])->isValid());
        $this->assertFalse($this->validator->validate($type, ['heading' => '   '])->isValid());
    }

    public function testFalseAndZeroAreValuesNotEmptiness(): void
    {
        $type = $this->type([
            ['name' => 'flag', 'type' => 'boolean', 'required' => true],
            ['name' => 'count', 'type' => 'number', 'required' => true],
        ]);

        $result = $this->validator->validate($type, ['flag' => false, 'count' => 0]);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->values['flag']);
        $this->assertSame(0, $result->values['count']);
    }

    public function testOptionalMissingFieldIsOmittedRatherThanStoredAsNull(): void
    {
        $type = $this->type([
            ['name' => 'heading', 'type' => 'text'],
            ['name' => 'subtitle', 'type' => 'text'],
        ]);

        $result = $this->validator->validate($type, ['heading' => 'Hi']);

        $this->assertTrue($result->isValid());
        $this->assertArrayNotHasKey('subtitle', $result->values);
    }

    public function testDefaultIsUsedWhenFieldAbsent(): void
    {
        $type = $this->type([
            ['name' => 'align', 'type' => 'select', 'options' => ['left', 'right'], 'default' => 'left'],
        ]);

        $result = $this->validator->validate($type, []);

        $this->assertSame('left', $result->values['align']);
    }

    public function testSelectRejectsValueOutsideOptions(): void
    {
        $type = $this->type([
            ['name' => 'align', 'type' => 'select', 'options' => ['left', 'right']],
        ]);

        $result = $this->validator->validate($type, ['align' => 'centre']);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('left, right', (string) $result->errorFor('align'));
    }

    public function testNumberRespectsMinAndMax(): void
    {
        $type = $this->type([['name' => 'n', 'type' => 'number', 'min' => 1, 'max' => 10]]);

        $this->assertFalse($this->validator->validate($type, ['n' => 0])->isValid());
        $this->assertFalse($this->validator->validate($type, ['n' => 11])->isValid());
        $this->assertTrue($this->validator->validate($type, ['n' => 5])->isValid());
    }

    public function testTextRespectsLengthBounds(): void
    {
        $type = $this->type([['name' => 't', 'type' => 'text', 'min' => 2, 'max' => 4]]);

        $this->assertFalse($this->validator->validate($type, ['t' => 'a'])->isValid());
        $this->assertFalse($this->validator->validate($type, ['t' => 'abcde'])->isValid());
        $this->assertTrue($this->validator->validate($type, ['t' => 'abc'])->isValid());
    }

    public function testUrlAndEmailAreValidated(): void
    {
        $type = $this->type([
            ['name' => 'link', 'type' => 'url'],
            ['name' => 'mail', 'type' => 'email'],
        ]);

        $this->assertFalse($this->validator->validate($type, ['link' => 'not a url'])->isValid());
        $this->assertFalse($this->validator->validate($type, ['mail' => 'not-an-email'])->isValid());
        $this->assertTrue(
            $this->validator->validate($type, [
                'link' => 'https://example.com',
                'mail' => 'a@example.com',
            ])->isValid()
        );
    }

    public function testDateMustBeRealAndCorrectlyFormatted(): void
    {
        $type = $this->type([['name' => 'd', 'type' => 'date']]);

        $this->assertTrue($this->validator->validate($type, ['d' => '2026-07-21'])->isValid());
        $this->assertFalse($this->validator->validate($type, ['d' => '21/07/2026'])->isValid());
        // Real format, impossible day.
        $this->assertFalse($this->validator->validate($type, ['d' => '2026-02-31'])->isValid());
    }

    public function testRepeaterValidatesEachRow(): void
    {
        $type = $this->type([
            [
                'name' => 'rows',
                'type' => 'repeater',
                'label' => 'Rows',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'required' => true],
                    ['name' => 'count', 'type' => 'number'],
                ],
            ],
        ]);

        $ok = $this->validator->validate($type, [
            'rows' => [
                ['title' => 'One', 'count' => 1],
                ['title' => 'Two', 'count' => 2],
            ],
        ]);

        $this->assertTrue($ok->isValid());
        $this->assertCount(2, $ok->values['rows']);
        $this->assertSame('One', $ok->values['rows'][0]['title']);
    }

    public function testRepeaterReportsWhichRowFailed(): void
    {
        $type = $this->type([
            [
                'name' => 'rows',
                'type' => 'repeater',
                'label' => 'Rows',
                'fields' => [['name' => 'title', 'type' => 'text', 'required' => true]],
            ],
        ]);

        $result = $this->validator->validate($type, [
            'rows' => [['title' => 'One'], ['title' => '']],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('entry 2', (string) $result->errorFor('rows'));
    }

    public function testRepeaterRespectsMinAndMax(): void
    {
        $type = $this->type([
            [
                'name' => 'rows',
                'type' => 'repeater',
                'min' => 1,
                'max' => 2,
                'fields' => [['name' => 'title', 'type' => 'text']],
            ],
        ]);

        $tooMany = $this->validator->validate($type, [
            'rows' => [['title' => 'a'], ['title' => 'b'], ['title' => 'c']],
        ]);

        $this->assertFalse($tooMany->isValid());
    }

    public function testRepeaterRejectsAssociativeArray(): void
    {
        $type = $this->type([
            [
                'name' => 'rows',
                'type' => 'repeater',
                'fields' => [['name' => 'title', 'type' => 'text']],
            ],
        ]);

        $result = $this->validator->validate($type, ['rows' => ['a' => ['title' => 'x']]]);

        $this->assertFalse($result->isValid());
    }

    public function testRepeaterStripsUndeclaredKeysInRows(): void
    {
        $type = $this->type([
            [
                'name' => 'rows',
                'type' => 'repeater',
                'fields' => [['name' => 'title', 'type' => 'text']],
            ],
        ]);

        $result = $this->validator->validate($type, [
            'rows' => [['title' => 'ok', 'injected' => 'nope']],
        ]);

        $this->assertTrue($result->isValid());
        $this->assertArrayNotHasKey('injected', $result->values['rows'][0]);
    }
}
