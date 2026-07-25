<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Sdk;

use ClickCms\Tools\TypeScript\TypeScriptClientGenerator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/generate-ts-client.php';

/**
 * What a site's schemas become in TypeScript.
 *
 * The whole value of generating these is that the front end stops compiling when
 * a schema changes under it. That only works if the emitted types say exactly
 * what the JSON says — a required field typed as optional, or a select widened
 * to `string`, turns a compile error a developer would have fixed in seconds
 * into a runtime bug on a live page. Worse, a generator that emits TypeScript
 * which does not parse at all fails silently: nobody looks at generated output,
 * so the failure surfaces as an inexplicably broken build.
 *
 * So these tests read the emitted source, not an intermediate model, and a
 * companion test compiles it.
 */
final class TypeScriptClientGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-tsgen-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config/sections', 0o775, true);
        mkdir($this->dir . '/config/collections', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $child) {
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    /** @param array<string, mixed> $spec */
    private function section(string $id, array $spec): void
    {
        file_put_contents(
            $this->dir . '/config/sections/' . $id . '.json',
            json_encode($spec, JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $spec */
    private function collection(string $id, array $spec): void
    {
        file_put_contents(
            $this->dir . '/config/collections/' . $id . '.json',
            json_encode($spec, JSON_THROW_ON_ERROR)
        );
    }

    private function render(): string
    {
        return (string) (new TypeScriptClientGenerator())->render($this->dir . '/config');
    }

    /* --------------------------------------------------------------- fields -- */

    /**
     * The distinction the whole exercise rests on. An editor may leave a field
     * blank, and a blank field is absent from the JSON rather than null in it —
     * so a front end reading it without a check must not compile.
     */
    public function testARequiredFieldIsMandatoryAndEverythingElseIsOptional(): void
    {
        $this->section('hero', ['label' => 'Hero', 'fields' => [
            ['name' => 'heading', 'type' => 'text', 'required' => true],
            ['name' => 'subheading', 'type' => 'text'],
            ['name' => 'strapline', 'type' => 'text', 'required' => false],
        ]]);

        $ts = $this->render();

        $this->assertStringContainsString('heading: string;', $ts);
        $this->assertStringContainsString('subheading?: string;', $ts);
        $this->assertStringContainsString('strapline?: string;', $ts, 'an explicit false is still optional');
    }

    public function testEachScalarFieldTypeBecomesTheTypeTheJsonActuallyCarries(): void
    {
        $this->section('kitchen-sink', ['fields' => [
            ['name' => 'a', 'type' => 'text', 'required' => true],
            ['name' => 'b', 'type' => 'textarea', 'required' => true],
            ['name' => 'c', 'type' => 'richtext', 'required' => true],
            ['name' => 'd', 'type' => 'number', 'required' => true],
            ['name' => 'e', 'type' => 'boolean', 'required' => true],
            ['name' => 'f', 'type' => 'image', 'required' => true],
            // A date is delivered as a string; typing it as Date would be a lie
            // about what arrives over the wire.
            ['name' => 'g', 'type' => 'date', 'required' => true],
        ]]);

        $ts = $this->render();

        $this->assertStringContainsString('a: string;', $ts);
        $this->assertStringContainsString('b: string;', $ts);
        $this->assertStringContainsString('c: string;', $ts);
        $this->assertStringContainsString('d: number;', $ts);
        $this->assertStringContainsString('e: boolean;', $ts);
        $this->assertStringContainsString('f: MediaRef;', $ts);
        $this->assertStringContainsString('g: string;', $ts);
    }

    /**
     * A select is a closed list, and saying so is what stops a front end
     * switching on a variant the schema never offered.
     */
    public function testASelectBecomesAUnionOfExactlyItsOptions(): void
    {
        $this->section('rich-text', ['fields' => [
            ['name' => 'width', 'type' => 'select', 'options' => ['narrow', 'wide', 'full'], 'required' => true],
        ]]);

        $this->assertStringContainsString(
            "width: 'narrow' | 'wide' | 'full';",
            $this->render()
        );
    }

    /** Nothing to narrow to, so nothing is claimed. */
    public function testASelectWithNoOptionsStaysAPlainString(): void
    {
        $this->section('rich-text', ['fields' => [
            ['name' => 'width', 'type' => 'select', 'required' => true],
        ]]);

        $this->assertStringContainsString('width: string;', $this->render());
    }

    public function testARepeaterBecomesAnArrayOfANestedInterface(): void
    {
        $this->section('card-grid', ['fields' => [
            ['name' => 'cards', 'type' => 'repeater', 'required' => true, 'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'body', 'type' => 'textarea'],
            ]],
        ]]);

        $ts = $this->render();

        $this->assertStringContainsString('cards: CardGridCardsItem[];', $ts);
        $this->assertStringContainsString('export interface CardGridCardsItem {', $ts);
        $this->assertMatchesRegularExpression(
            '/export interface CardGridCardsItem \{\s*(\/\*\*.*?\*\/\s*)?title: string;/s',
            $ts,
            'the nested interface must carry the sub-fields, with their own optionality'
        );
        $this->assertStringContainsString('body?: string;', $ts);
    }

    /** A repeater declaring nothing must still produce an array, not a syntax error. */
    public function testARepeaterWithNoSubFieldsDegradesToAnOpenRecord(): void
    {
        $this->section('rows', ['fields' => [
            ['name' => 'items', 'type' => 'repeater', 'required' => true],
        ]]);

        $this->assertStringContainsString('items: Array<Record<string, unknown>>;', $this->render());
    }

    public function testAReferenceIsASlugAndAMultipleReferenceIsAListOfThem(): void
    {
        $this->collection('post', ['fields' => [
            ['name' => 'author', 'type' => 'reference', 'references' => 'team-member', 'required' => true],
            ['name' => 'related', 'type' => 'reference', 'references' => 'post', 'multiple' => true, 'required' => true],
        ]]);

        $ts = $this->render();

        $this->assertStringContainsString('author: string;', $ts);
        $this->assertStringContainsString('related: string[];', $ts);
    }

    /**
     * A plugin can introduce a field type this generator has never heard of. The
     * one unacceptable outcome is emitting something that does not parse and
     * taking every other type down with it; the second-worst is guessing a
     * plausible `string` that compiles and then fails at runtime.
     */
    public function testAnUnknownFieldTypeWidensRatherThanBreakingTheFile(): void
    {
        $this->section('exotic', ['fields' => [
            ['name' => 'colour', 'type' => 'colour-picker', 'required' => true],
            ['name' => 'heading', 'type' => 'text', 'required' => true],
        ]]);

        $ts = $this->render();

        $this->assertStringContainsString('colour: unknown;', $ts);
        $this->assertStringContainsString('heading: string;', $ts, 'the rest of the section survives');
    }

    /* ------------------------------------------------------------- sections -- */

    public function testEverySectionTypeJoinsTheDiscriminatedUnion(): void
    {
        $this->section('rich-text', ['fields' => [['name' => 'body', 'type' => 'richtext', 'required' => true]]]);
        $this->section('facts', ['fields' => [['name' => 'heading', 'type' => 'text']]]);

        $ts = $this->render();

        $this->assertStringContainsString("type: 'rich-text';", $ts);
        $this->assertStringContainsString("type: 'facts';", $ts);
        $this->assertMatchesRegularExpression(
            '/export type Section =\s*\|\s*FactsSection\s*\|\s*RichTextSection;/',
            $ts,
            'the union is what makes `switch (section.type)` narrow'
        );
    }

    /**
     * A site with nothing declared is a fresh install, not a broken one, and its
     * front end still has to compile.
     */
    public function testASiteWithNoSectionTypesStillGetsAUsableSectionType(): void
    {
        $ts = $this->render();

        $this->assertStringContainsString('export interface Section {', $ts);
        $this->assertStringContainsString('values: Record<string, unknown>;', $ts);
        // Not a union: an empty one is `never`, and every page would be unrenderable.
        $this->assertStringNotContainsString('export type Section =', $ts);
    }

    /* ---------------------------------------------------------- collections -- */

    public function testACollectionBecomesAnEntryTypeAndJoinsTheNameMap(): void
    {
        $this->collection('team-member', ['label' => 'Team members', 'fields' => [
            ['name' => 'name', 'type' => 'text', 'required' => true],
            ['name' => 'bio', 'type' => 'textarea'],
        ]]);

        $ts = $this->render();

        $this->assertStringContainsString('export interface TeamMemberFields {', $ts);
        $this->assertStringContainsString('export type TeamMemberEntry = CollectionEntry<TeamMemberFields>;', $ts);
        // The map is how `listEntries('team-member')` knows what it is returning.
        $this->assertStringContainsString("'team-member': TeamMemberFields;", $ts);
    }

    public function testACollectionIdThatIsNotAnIdentifierIsQuotedInTheMap(): void
    {
        $this->collection('case-study', ['fields' => [['name' => 'title', 'type' => 'text', 'required' => true]]]);

        // Unquoted, `case-study: X` is a subtraction, and the file does not parse.
        $this->assertStringContainsString("'case-study': CaseStudyFields;", $this->render());
    }

    /* ---------------------------------------------------------- the file it -- */

    public function testTheFileSaysItIsGeneratedAndHowToReproduceIt(): void
    {
        $ts = (string) (new TypeScriptClientGenerator('php scripts/generate-ts-client.php'))
            ->render($this->dir . '/config');

        $this->assertStringContainsString('GENERATED FILE', $ts);
        $this->assertStringContainsString('do not edit', $ts);
        $this->assertStringContainsString('php scripts/generate-ts-client.php', $ts);
        $this->assertStringContainsString('lost the next time it runs', $ts);
    }

    /**
     * A generated file that changes on every run shows up in every diff, and a
     * reviewer who learns to ignore it stops noticing the day a type really did
     * change.
     */
    public function testRegeneratingAnUnchangedSiteProducesAnIdenticalFile(): void
    {
        $this->section('hero', ['fields' => [['name' => 'heading', 'type' => 'text', 'required' => true]]]);

        $first = $this->render();
        $second = $this->render();

        $this->assertSame($first, $second);
    }

    public function testTwoSchemasCannotProduceTwoDeclarationsOfTheSameName(): void
    {
        // Both pascal-case to "CardGrid". Left alone, TypeScript sees a duplicate
        // identifier and the whole file fails to compile.
        $this->section('card-grid', ['fields' => [['name' => 'a', 'type' => 'text', 'required' => true]]]);
        $this->section('card_grid', ['fields' => [['name' => 'b', 'type' => 'text', 'required' => true]]]);

        $ts = $this->render();

        $this->assertSame(1, substr_count($ts, 'export interface CardGridSection {'));
        $this->assertSame(1, substr_count($ts, 'export interface CardGridSection2 {'));
    }

    /** One typo in one schema must not cost the front end every other type. */
    public function testAMalformedSchemaIsSkippedRatherThanLosingTheRest(): void
    {
        file_put_contents($this->dir . '/config/sections/broken.json', '{ this is not json');
        $this->section('hero', ['fields' => [['name' => 'heading', 'type' => 'text', 'required' => true]]]);

        $ts = $this->render();

        $this->assertStringContainsString('export interface HeroSection {', $ts);
        $this->assertStringNotContainsString('Broken', $ts);
    }

    /* -------------------------------------------------------------- writing -- */

    public function testGeneratingWritesOneFileTheClientCanImport(): void
    {
        $this->section('hero', ['fields' => [['name' => 'heading', 'type' => 'text', 'required' => true]]]);

        $written = (new TypeScriptClientGenerator())->generate($this->dir . '/config', $this->dir . '/out/src');

        $this->assertSame([$this->dir . '/out/src/types.ts'], $written, 'the directory is created if absent');
        $this->assertFileExists($this->dir . '/out/src/types.ts');
        $this->assertStringContainsString('export interface HeroSection {', file_get_contents($this->dir . '/out/src/types.ts'));
    }

    /**
     * Pointed at somewhere there is no site, the generator writes nothing and
     * says so. Throwing would fail a build over a mistyped path; writing an empty
     * file would quietly replace a front end's working types with nothing.
     */
    public function testAConfigDirectoryThatDoesNotExistProducesNoOutputAndNoException(): void
    {
        $generator = new TypeScriptClientGenerator();
        $out = $this->dir . '/out';

        $written = $generator->generate($this->dir . '/nowhere', $out);

        $this->assertSame([], $written);
        $this->assertNull($generator->render($this->dir . '/nowhere'));
        $this->assertDirectoryDoesNotExist($out, 'nothing was written, so nothing was created');
    }

    /**
     * Half a site — pages but no collections, or the reverse — is ordinary, and
     * the missing half must still leave a file the client compiles against.
     */
    public function testAMissingSchemaDirectoryLeavesTheSurfaceIntact(): void
    {
        rmdir($this->dir . '/config/collections');
        $this->section('hero', ['fields' => [['name' => 'heading', 'type' => 'text', 'required' => true]]]);

        $ts = $this->render();

        $this->assertStringContainsString('export interface HeroSection {', $ts);
        $this->assertStringContainsString('export interface CollectionData {', $ts);
        $this->assertStringContainsString('export type CollectionName =', $ts);
    }
}
