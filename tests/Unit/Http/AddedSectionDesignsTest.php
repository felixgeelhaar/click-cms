<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\FieldDefinition;
use Click\Cms\Domain\Schema\FieldType;
use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The section designs added to fill out the starter set beyond the original six.
 *
 * These designs are configuration only — not one line of the renderer is theirs
 * — which means the only thing that can be wrong with them is what they compose
 * out of. A field type behaves differently depending on where it sits: a select
 * becomes a modifier class, a url paired with a `labelField` consumes that field
 * instead of printing it, and inside a repeater neither of those rules applies.
 * Get that wrong and the page shows a raw web address as a sentence, or a
 * picture's description as a paragraph under the picture it exists to replace.
 * Nothing in the schema layer catches either, because both are valid schemas.
 *
 * So each design is rendered here with the values an editor would actually type,
 * and the output is asserted on. That is the only place the mistake is visible.
 */
final class AddedSectionDesignsTest extends TestCase
{
    /**
     * The designs this test owns. Listed rather than globbed so the assertions
     * stay about these files: the starter directory is shared, and a design added
     * later should be proved by its own test rather than silently inheriting
     * these rules.
     */
    private const DESIGNS = [
        'section-heading',
        'faq',
        'pricing',
        'quote',
        'gallery',
        'people',
        'details',
        'logos',
    ];

    private JsonSectionTypeRepository $types;
    private SectionRenderer $renderer;
    private SectionValidator $validator;
    private MediaService $media;
    private string $mediaDir;

    /** A picture whose description was written in the media library. */
    private string $described;

    /** A picture with no description of its own, so the row must supply one. */
    private string $undescribed;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);

        $this->mediaDir = sys_get_temp_dir() . '/click-cms-designs-' . bin2hex(random_bytes(6));
        $this->media = new MediaService($this->mediaDir);

        $this->described = $this->picture('bench.svg', 'A finished oak bench on the workshop floor.');
        $this->undescribed = $this->picture('mark.svg', '');

        $this->types = new JsonSectionTypeRepository($root . '/config/sections');
        $this->renderer = new SectionRenderer($this->types, $this->media);
        $this->validator = new SectionValidator();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->mediaDir);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * The values an editor would type into each design.
     *
     * @return array<string, mixed>
     */
    private function values(string $design): array
    {
        return match ($design) {
            'section-heading' => [
                'heading' => 'What it costs',
                'intro' => "Everything below is a price we have charged.\n\nNothing here is an estimate that grows.",
            ],
            'faq' => [
                'heading' => 'Questions we are asked most',
                'intro' => 'If yours is not here, write to us.',
                'items' => [
                    [
                        'title' => 'How long does a commission take?',
                        'answer' => '<p>Six weeks from the day you accept the drawing.</p>',
                    ],
                    [
                        'title' => 'Can you repair a piece you did not make?',
                        'answer' => '<p>Usually. Send a photograph of the joint that has failed.</p>',
                    ],
                ],
            ],
            'pricing' => [
                'heading' => 'Ways to work with us',
                'intro' => '<p>Every price includes delivery.</p>',
                'columns' => '3',
                'plans' => [
                    [
                        'title' => 'Repair',
                        'price' => 'from 180',
                        'summary' => 'For a piece with one thing wrong with it.',
                        'features' => "Collection and return\nA written report first",
                    ],
                    [
                        'title' => 'Commission',
                        'price' => 'On request',
                        'summary' => 'For something that does not exist yet.',
                        'features' => "A drawing and a fixed price first\nHalf on acceptance",
                    ],
                ],
                'buttonLabel' => 'Ask for a quote',
                'buttonUrl' => '/contact',
            ],
            'quote' => [
                'quote' => 'They took the measurements of a room that is not square.',
                'attribution' => 'Amara Ndiaye',
                'role' => 'Head of Facilities, Northgate Practice',
                'portrait' => $this->undescribed,
                'portraitAlt' => 'Amara Ndiaye at the reception desk.',
            ],
            'gallery' => [
                'heading' => 'Work leaving the shop',
                'intro' => 'Photographed on the floor, before delivery.',
                'columns' => '2',
                'images' => [
                    ['image' => $this->described, 'caption' => 'Oak bench, 1.8 metres.'],
                    // No caption: the picture must still render, alone.
                    ['image' => $this->described],
                ],
            ],
            'people' => [
                'heading' => 'Who you will deal with',
                'columns' => '3',
                'people' => [
                    [
                        'photo' => $this->undescribed,
                        'title' => 'Ruth Ellery',
                        'role' => 'Workshop manager',
                        'bio' => 'Joined in 2011. Runs the bench diary.',
                        'email' => 'ruth@example.com',
                    ],
                    [
                        'title' => 'Tomas Lindqvist',
                        'role' => 'Chairmaker',
                        'bio' => 'Steam-bends every spindle-back that leaves the shop.',
                    ],
                ],
            ],
            'details' => [
                'heading' => 'Opening hours',
                'intro' => 'We close for the first week in August.',
                'rows' => [
                    ['label' => 'Monday', 'value' => 'Closed'],
                    ['label' => 'Tuesday to Friday', 'value' => '9:00 to 17:00'],
                ],
            ],
            'logos' => [
                'heading' => 'Accredited by',
                'columns' => '4',
                'logos' => [
                    ['logo' => $this->undescribed, 'title' => 'Guild of Master Craftsmen'],
                    ['logo' => $this->undescribed, 'title' => 'FSC Chain of Custody'],
                ],
            ],
            default => [],
        };
    }

    /** @return iterable<string, array{string}> */
    public static function designs(): iterable
    {
        foreach (self::DESIGNS as $id) {
            yield $id => [$id];
        }
    }

    // ------------------------------------------------------- the whole set

    #[DataProvider('designs')]
    public function testTheDesignShips(string $design): void
    {
        $this->assertNotNull(
            $this->types->find($design),
            "config/sections/{$design}.json is missing or did not parse"
        );
        $this->assertSame([], $this->types->errors());
    }

    /**
     * The values in this test are what an editor types, so the validator has to
     * accept them. A fixture the validator rejects would prove the markup of a
     * section nobody could ever save.
     */
    #[DataProvider('designs')]
    public function testAnEditorsValuesAreAccepted(string $design): void
    {
        $type = $this->types->find($design);
        $this->assertNotNull($type);

        $result = $this->validator->validate($type, $this->values($design));

        $this->assertSame([], $result->errors, "{$design} rejected its own worked example");
    }

    /**
     * Nothing renders as an empty shell. An element with a class and no content
     * is a field the design declared, the editor filled in, and the renderer had
     * nowhere to put — visible in a theme as unexplained vertical space.
     */
    #[DataProvider('designs')]
    public function testNothingRendersAsAnEmptyElement(string $design): void
    {
        $html = $this->render($design);

        $this->assertNotSame('', $html, "{$design} rendered nothing at all");
        $this->assertSame(
            0,
            preg_match('#<(p|div|ul|li|h2|h3)\b[^>]*>\s*</\1>#', $html),
            "{$design} produced an empty element: {$html}"
        );
    }

    /**
     * Every picture is announced by something. An `alt=""` here would be a
     * decorative image, and none of these designs has one: an editor who adds a
     * portrait, a photograph or a certification mark meant it to carry meaning.
     */
    #[DataProvider('designs')]
    public function testEveryPictureHasADescription(string $design): void
    {
        $html = $this->render($design);

        preg_match_all('#<img[^>]*>#', $html, $tags);

        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression(
                '#alt="[^"]+"#',
                $tag,
                "{$design} rendered a picture with no description: {$tag}"
            );
        }
    }

    /**
     * A select is a choice about presentation, so it must reach the page as a
     * modifier class and never as prose. "3" printed as a paragraph is what this
     * rule exists to stop.
     */
    #[DataProvider('designs')]
    public function testPresentationChoicesBecomeClassesRatherThanText(string $design): void
    {
        $type = $this->types->find($design);
        $this->assertNotNull($type);

        $html = $this->render($design);
        $values = $this->values($design);

        foreach ($type->fields as $field) {
            if ($field->type !== FieldType::Select || !isset($values[$field->name])) {
                continue;
            }

            $chosen = (string) $values[$field->name];

            $this->assertStringContainsString(
                'cms-section--' . $field->name . '-' . $chosen,
                $html,
                "{$design} did not turn {$field->name} into a modifier class"
            );
            $this->assertSame(
                0,
                preg_match('#<p[^>]*>' . preg_quote($chosen, '#') . '</p>#', $html),
                "{$design} printed the {$field->name} choice as a paragraph"
            );
        }
    }

    /**
     * Every image field says how wide the section shows it. Without it the media
     * library cannot tell an editor that a 400-pixel file is too small for a
     * full-width band and fine for a card — the warning it shows becomes a guess.
     */
    #[DataProvider('designs')]
    public function testEveryImageFieldDeclaresTheWidthItIsShownAt(string $design): void
    {
        $type = $this->types->find($design);
        $this->assertNotNull($type);

        foreach ($this->imageFields($type) as $path => $field) {
            $this->assertNotNull(
                $field->displayWidth,
                "{$design}.{$path} does not declare displayWidth"
            );
        }
    }

    /**
     * Every field an editor sees has wording of its own. A field labelled by its
     * own machine name ("portraitAlt") is a field a non-technical editor has to
     * guess at.
     */
    #[DataProvider('designs')]
    public function testEveryFieldIsLabelledForAnEditor(string $design): void
    {
        $type = $this->types->find($design);
        $this->assertNotNull($type);

        foreach ($type->fields as $field) {
            $this->assertNotSame('', trim($field->label));
            $this->assertNotSame($field->name, $field->label, "{$design}.{$field->name} has no real label");

            foreach ($field->fields as $sub) {
                $this->assertNotSame('', trim($sub->label));
            }
        }
    }

    /**
     * Every column count these designs offer has styling behind it.
     *
     * `.cms-items` is a single-column grid until a `cms-section--columns-N` class
     * says otherwise, so offering an editor a choice the theme does not implement
     * is offering them a setting that does nothing — the grid silently stacks.
     */
    #[DataProvider('designs')]
    public function testEveryColumnChoiceIsOneTheThemeImplements(string $design): void
    {
        $type = $this->types->find($design);
        $this->assertNotNull($type);

        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/themes/default/theme.css');

        foreach ($type->fields as $field) {
            if ($field->type !== FieldType::Select || $field->name !== 'columns') {
                continue;
            }

            foreach ($field->options as $option) {
                $this->assertStringContainsString(
                    '.cms-section--columns-' . $option . ' .cms-items',
                    $css,
                    "{$design} offers {$option} columns but the theme has no rule for it"
                );
            }
        }
    }

    // ---------------------------------------------------- design by design

    public function testAHeadingAndLeadInBreaksAPageIntoParts(): void
    {
        $html = $this->render('section-heading');

        $this->assertStringContainsString('<h2 class="cms-field cms-field--heading">What it costs</h2>', $html);
        // A blank line in the lead-in becomes a second paragraph, not a <br>.
        $this->assertSame(2, preg_match_all('#<p>#', $html));
    }

    public function testEachQuestionIsAHeadingWithItsAnswerBeneath(): void
    {
        $html = $this->render('faq');

        $this->assertSame(2, preg_match_all('#<li class="cms-item">#', $html));
        $this->assertStringContainsString(
            '<h2 class="cms-field cms-field--title">How long does a commission take?</h2>',
            $html
        );
        // The answer is rich text, so its own markup survives sanitising.
        $this->assertStringContainsString(
            '<div class="cms-field cms-field--answer"><p>Six weeks from the day you accept the drawing.</p></div>',
            $html
        );
    }

    /**
     * The plans' shared button says what it does. A url field whose wording comes
     * from a sibling prints that wording and nothing else — the address stays in
     * the href, where a reader never sees it.
     */
    public function testPricingsButtonReadsAsWordsRatherThanAnAddress(): void
    {
        $html = $this->render('pricing');

        $this->assertStringContainsString(
            '<a href="/contact" rel="noopener noreferrer">Ask for a quote</a>',
            $html
        );
        // The label is consumed by the link, so it appears once, inside it.
        $this->assertSame(1, substr_count($html, 'Ask for a quote'));
        $this->assertSame(0, preg_match('#<p[^>]*>/contact</p>#', $html));
    }

    /**
     * A plan's included items are one field with a line each, because a repeater
     * cannot hold a repeater. They must at least survive as separate lines.
     */
    public function testAPlansIncludedItemsKeepTheirLineBreaks(): void
    {
        $html = $this->render('pricing');

        $this->assertStringContainsString('Collection and return<br', $html);
    }

    /**
     * The portrait's description lands in the alt attribute and is not also
     * printed. Printing it is the bug this pairing exists to avoid: a sentence
     * describing a picture, set underneath the picture it describes.
     */
    public function testTheQuotesPortraitDescriptionGoesOnlyIntoTheAltAttribute(): void
    {
        $html = $this->render('quote');
        $description = 'Amara Ndiaye at the reception desk.';

        $this->assertStringContainsString('alt="' . $description . '"', $html);
        $this->assertSame(1, substr_count($html, $description));
        $this->assertSame(0, preg_match('#<p[^>]*>' . preg_quote($description, '#') . '</p>#', $html));
        // The words, the name and the role are all still there.
        $this->assertStringContainsString('cms-field--quote', $html);
        $this->assertStringContainsString('>Amara Ndiaye</p>', $html);
        $this->assertStringContainsString('>Head of Facilities, Northgate Practice</p>', $html);
    }

    /**
     * A gallery picture is announced by the description written in the media
     * library, not by its caption. The caption is wording for a sighted reader;
     * the description is what the picture is. They are different sentences and
     * the design keeps them apart.
     */
    public function testAGalleryPictureIsAnnouncedByItsLibraryDescription(): void
    {
        $html = $this->render('gallery');

        $this->assertSame(
            2,
            substr_count($html, 'alt="A finished oak bench on the workshop floor."'),
            'both pictures should take their description from the library'
        );
        $this->assertStringContainsString('>Oak bench, 1.8 metres.</p>', $html);
        // The captionless picture still renders, on its own.
        $this->assertSame(2, preg_match_all('#<li class="cms-item">#', $html));
    }

    /**
     * A photograph with no description of its own falls back to the person's
     * name, so a listing of people is never a row of unannounced pictures.
     */
    public function testAPersonsPhotographFallsBackToTheirName(): void
    {
        $html = $this->render('people');

        $this->assertStringContainsString('alt="Ruth Ellery"', $html);
        $this->assertStringContainsString('<a href="mailto:ruth@example.com">', $html);
        // The second person has no photograph and no email, and still renders.
        $this->assertStringContainsString('>Tomas Lindqvist</h2>', $html);
    }

    public function testADetailsListPairsEveryLabelWithItsValue(): void
    {
        $html = $this->render('details');

        $this->assertStringContainsString(
            '<li class="cms-item">'
                . '<p class="cms-field cms-field--label">Monday</p>'
                . '<p class="cms-field cms-field--value">Closed</p>'
                . '</li>',
            $html
        );
    }

    public function testEveryMarkCarriesTheNameOfWhatItStandsFor(): void
    {
        $html = $this->render('logos');

        $this->assertStringContainsString('alt="Guild of Master Craftsmen"', $html);
        $this->assertStringContainsString('>FSC Chain of Custody</h2>', $html);
    }

    // ------------------------------------------------------------- helpers

    private function render(string $design): string
    {
        $type = $this->types->find($design);
        $this->assertNotNull($type);

        // Rendered from what the validator stored, not from the raw fixture, so
        // this is the same journey an editor's input actually makes.
        $result = $this->validator->validate($type, $this->values($design));
        $this->assertSame([], $result->errors);

        return $this->renderer->render(Content::create(
            ContentKey::page('proof'),
            ['title' => 'Proof', 'sections' => [['type' => $design, 'values' => $result->values]]]
        ));
    }

    /**
     * Every image field in a design, top level and inside repeaters, keyed by a
     * readable path.
     *
     * @return array<string, FieldDefinition>
     */
    private function imageFields(SectionType $type): array
    {
        $found = [];

        foreach ($type->fields as $field) {
            if ($field->type === FieldType::Image) {
                $found[$field->name] = $field;
            }

            foreach ($field->fields as $sub) {
                if ($sub->type === FieldType::Image) {
                    $found[$field->name . '.' . $sub->name] = $sub;
                }
            }
        }

        return $found;
    }

    /** Store a picture the way an upload does, then give it a description. */
    private function picture(string $name, string $alt): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600">'
            . '<rect width="900" height="600" fill="#cbd5e1"/></svg>';

        $tmp = (string) tempnam(sys_get_temp_dir(), 'click-designs-');
        file_put_contents($tmp, $svg);

        $result = $this->media->store([
            'name' => $name,
            'type' => 'image/svg+xml',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp) ?: strlen($svg),
        ]);
        @unlink($tmp);

        $this->assertNotNull($result['item'], (string) ($result['error'] ?? ''));

        if ($alt !== '') {
            $this->media->updateAlt($result['item']->id, $alt);
        }

        return $result['item']->id;
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $child) {
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
