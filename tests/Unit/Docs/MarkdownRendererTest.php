<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use ClickCms\Tools\Docs\DocumentDefect;
use ClickCms\Tools\Docs\ImageAsset;
use ClickCms\Tools\Docs\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/docs/bootstrap.php';

/**
 * The renderer is hand-written because this project takes "requires PHP and
 * nothing else" literally, and a hand-written Markdown parser earns its place
 * only if its failure modes are pinned down. Two properties matter more than
 * the rest, and most of this file is about them.
 *
 * The first is escaping. These docs are prose *about* HTML — `<section>`,
 * `-->`, `&`, shell redirects, regexes — so an unescaped angle bracket does not
 * produce a visible mistake, it produces a page that quietly swallows the rest
 * of a paragraph into a tag that was never meant to exist.
 *
 * The second is that inline formatting must stop at the edge of a code span.
 * `**` and `_` and `~` are ordinary characters in a shell command and in a
 * regex; a renderer that reads them as emphasis inside code silently changes
 * what the documentation says the reader should type.
 */
final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    private function render(string $markdown): string
    {
        return $this->renderer->render($markdown)->html;
    }

    // ------------------------------------------------------------------
    // Escaping
    // ------------------------------------------------------------------

    #[DataProvider('metacharactersInCodeSpans')]
    public function testCodeSpansEscapeHtmlMetacharacters(string $source, string $expected): void
    {
        $html = $this->render('Text with `' . $source . '` inside.');

        $this->assertStringContainsString('<code>' . $expected . '</code>', $html);
        $this->assertStringNotContainsString('<' . $source, $html);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function metacharactersInCodeSpans(): array
    {
        return [
            'element name' => ['<section>', '&lt;section&gt;'],
            'comment close' => ['-->', '--&gt;'],
            'html comment' => ['<!-- -->', '&lt;!-- --&gt;'],
            'ampersand' => ['a && b', 'a &amp;&amp; b'],
            'shell redirect' => ['2>&1', '2&gt;&amp;1'],
            'generic' => ['array<string, int>', 'array&lt;string, int&gt;'],
            'entity stays literal' => ['java&#9;script:', 'java&amp;#9;script:'],
            'quoted regex' => ['"^\\d+\\.\\d+$"', '&quot;^\\d+\\.\\d+$&quot;'],
            'namespace' => ['Core\\Application', 'Core\\Application'],
        ];
    }

    public function testMetacharactersInProseAreEscaped(): void
    {
        $html = $this->render('A < B & C > D, and "quoted" too.');

        $this->assertStringContainsString('A &lt; B &amp; C &gt; D', $html);
        $this->assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function testFencedCodeEscapesHtmlAndRendersItAsVisibleText(): void
    {
        $html = $this->render(<<<'MD'
            ```html
            <section class="hero">
              <p>a &amp; b</p>
            </section>
            <!-- a comment -->
            ```
            MD);

        $this->assertStringContainsString('&lt;section class=&quot;hero&quot;&gt;', $html);
        $this->assertStringContainsString('&lt;p&gt;a &amp;amp; b&lt;/p&gt;', $html);
        $this->assertStringContainsString('&lt;!-- a comment --&gt;', $html);
        // Nothing from the fence became markup: the only tags are ours.
        $this->assertSame(
            ['figure', 'figcaption', 'pre', 'code'],
            $this->tagNames($html),
        );
    }

    public function testFenceCarriesItsLanguageLabel(): void
    {
        $html = $this->render("```bash\nls -la\n```");

        $this->assertStringContainsString('<figcaption>bash</figcaption>', $html);
        $this->assertStringContainsString('<code class="language-bash">', $html);
    }

    public function testFenceWithoutLanguageHasNoLabel(): void
    {
        $html = $this->render("```\nplain\n```");

        $this->assertStringNotContainsString('figcaption', $html);
        $this->assertStringContainsString('<pre><code>plain</code></pre>', $html);
    }

    public function testFenceLanguageCannotEscapeTheClassAttribute(): void
    {
        $html = $this->render("```bash\" onload=\"alert(1)\nls\n```");

        $this->assertStringNotContainsString('onload', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    // ------------------------------------------------------------------
    // Inline formatting must not run inside code
    // ------------------------------------------------------------------

    #[DataProvider('formattingCharactersInCode')]
    public function testInlineFormattingDoesNotApplyInsideCodeSpans(string $source): void
    {
        $html = $this->render('See `' . $source . '` here.');

        foreach (['<strong>', '<em>', '<del>', '<a '] as $tag) {
            $this->assertStringNotContainsString($tag, $html, "Formatted inside a code span: {$source}");
        }
    }

    /** @return array<string, array{0: string}> */
    public static function formattingCharactersInCode(): array
    {
        return [
            'strong markers' => ['**not bold**'],
            'em markers' => ['*not italic*'],
            'strike markers' => ['~~not struck~~'],
            'underscores' => ['hook_<name_with_underscores>'],
            'link syntax' => ['[not](a-link)'],
            'cron' => ['17 3 * * * php bin/click-update.php'],
        ];
    }

    public function testInlineFormattingDoesNotApplyInsideFences(): void
    {
        $html = $this->render("```\n**still asterisks** and _underscores_\n```");

        $this->assertStringContainsString('**still asterisks** and _underscores_', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<em>', $html);
    }

    public function testCodeSpanInsideLinkTextSurvives(): void
    {
        // The README really writes this, and a parser that splits on code spans
        // before parsing links loses the link entirely.
        $html = $this->render('See [`docs/core.md`](core.md) for why.');

        $this->assertStringContainsString('<a href="core.md"><code>docs/core.md</code></a>', $html);
    }

    public function testDoubleBacktickSpanHoldsABacktick(): void
    {
        $html = $this->render('Write ``a ` b`` like that.');

        $this->assertStringContainsString('<code>a ` b</code>', $html);
    }

    public function testUnclosedBacktickIsLiteral(): void
    {
        $html = $this->render('A lone ` backtick.');

        $this->assertStringContainsString('A lone ` backtick.', $html);
        $this->assertStringNotContainsString('<code>', $html);
    }

    // ------------------------------------------------------------------
    // Inline constructs
    // ------------------------------------------------------------------

    public function testStrongEmphasisAndStrikethrough(): void
    {
        $html = $this->render('**bold**, *italic*, _also italic_ and ~~struck~~.');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<em>also italic</em>', $html);
        $this->assertStringContainsString('<del>struck</del>', $html);
    }

    public function testStrikethroughWrapsStrong(): void
    {
        $html = $this->render('~~**Continuous integration.**~~ *(small)* **Done.**');

        $this->assertStringContainsString('<del><strong>Continuous integration.</strong></del>', $html);
        $this->assertStringContainsString('<em>(small)</em>', $html);
        $this->assertStringContainsString('<strong>Done.</strong>', $html);
    }

    public function testUnderscoresInsideAWordAreNotEmphasis(): void
    {
        $html = $this->render('The value snake_case_name stays whole.');

        $this->assertStringContainsString('snake_case_name', $html);
        $this->assertStringNotContainsString('<em>', $html);
    }

    public function testLinksAndAutolinks(): void
    {
        $html = $this->render('Read [the spec](https://example.test/a) or open <http://localhost:8080/admin/>.');

        $this->assertStringContainsString('href="https://example.test/a"', $html);
        $this->assertStringContainsString(
            '<a href="http://localhost:8080/admin/">http://localhost:8080/admin/</a>',
            $html,
        );
    }

    public function testLinkQueryStringIsEscapedNotDropped(): void
    {
        $html = $this->render('[badge](https://example.test/b?style=flat&logo=php)');

        $this->assertStringContainsString('href="https://example.test/b?style=flat&amp;logo=php"', $html);
    }

    // ------------------------------------------------------------------
    // Images
    //
    // Two kinds, and the difference is the whole point. A screenshot in the
    // repository is a picture and must be shown; a shields.io badge is a
    // request to a third-party host and must never be made.
    // ------------------------------------------------------------------

    public function testARemoteImageStaysALinkSoTheSiteMakesNoExternalRequests(): void
    {
        $html = $this->render('![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4)');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(
            '<a class="badge" href="https://img.shields.io/badge/PHP-8.1+-777BB4">PHP</a>',
            $html,
        );
    }

    /** Even on a page that does render local images, the badge is still a link. */
    public function testARemoteImageStaysALinkEvenWhereLocalImagesRender(): void
    {
        $html = $this->renderWithImages(
            "![Admin](images/admin.png)\n\n![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4)",
        );

        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringContainsString('class="badge" href="https://img.shields.io/', $html);
    }

    public function testALocalImageBecomesAnImgWithItsIntrinsicSize(): void
    {
        $html = $this->renderWithImages('Look at ![the dashboard](images/admin.png) closely.');

        $this->assertStringContainsString(
            '<img src="../assets/docs/images/admin.png" alt="the dashboard"'
                . ' width="800" height="600" loading="lazy">',
            $html,
        );
    }

    public function testALocalImageOfUnknownSizeOmitsTheDimensions(): void
    {
        $html = $this->renderWithImages('Look at ![a diagram](images/plan.svg) closely.');

        $this->assertStringContainsString('src="../assets/docs/images/plan.svg"', $html);
        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function testAnImageAloneInItsParagraphBecomesAFigureCaptionedWithItsAlt(): void
    {
        $html = $this->renderWithImages("Before.\n\n![The page list](images/admin.png)\n\nAfter.");

        $this->assertStringContainsString('<figure class="image">', $html);
        $this->assertStringContainsString('<figcaption>The page list</figcaption>', $html);
        $this->assertStringNotContainsString('<p><img', $html);
    }

    public function testAnImageInASentenceIsNotTurnedIntoAFigure(): void
    {
        $html = $this->renderWithImages('The ![toolbar](images/admin.png) sits at the top.');

        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringContainsString('<p>The <img', $html);
    }

    /**
     * An image with no alt text is invisible to a reader using a screen reader,
     * which on a page whose argument *is* the screenshot means the page says
     * nothing at all. The build refuses it rather than shipping it.
     */
    public function testAnImageWithoutAltTextIsRefused(): void
    {
        $this->expectException(DocumentDefect::class);
        $this->expectExceptionMessageMatches('#images/admin\.png#');
        $this->renderWithImages('![](images/admin.png)');
    }

    public function testARemoteImageWithoutAltTextIsRefusedToo(): void
    {
        $this->expectException(DocumentDefect::class);
        $this->render('![](https://img.shields.io/badge/PHP-8.1+-777BB4)');
    }

    /** A renderer given no image resolver cannot know what is local: link it. */
    private function renderWithImages(string $markdown): string
    {
        $renderer = new MarkdownRenderer(null, static function (string $destination): ?ImageAsset {
            return match ($destination) {
                'images/admin.png' => new ImageAsset('../assets/docs/images/admin.png', 800, 600),
                'images/plan.svg' => new ImageAsset('../assets/docs/images/plan.svg', null, null),
                default => null,
            };
        });

        return $renderer->render($markdown)->html;
    }

    public function testBackslashEscapesPunctuation(): void
    {
        $html = $this->render('Literal \\*asterisks\\* here.');

        $this->assertStringContainsString('Literal *asterisks* here.', $html);
        $this->assertStringNotContainsString('<em>', $html);
    }

    // ------------------------------------------------------------------
    // Block constructs
    // ------------------------------------------------------------------

    public function testParagraphsSplitOnBlankLines(): void
    {
        $html = $this->render("One line\nwrapped.\n\nSecond paragraph.");

        $this->assertStringContainsString("<p>One line\nwrapped.</p>", $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html);
    }

    public function testHeadingsOfEveryLevel(): void
    {
        $html = $this->render("# One\n\n## Two\n\n### Three\n\n#### Four\n\n##### Five\n\n###### Six");

        foreach (range(1, 6) as $level) {
            $this->assertStringContainsString("<h{$level} id=", $html);
        }
    }

    public function testThematicBreak(): void
    {
        $this->assertStringContainsString('<hr>', $this->render("a\n\n---\n\nb"));
        $this->assertStringContainsString('<hr>', $this->render("a\n\n***\n\nb"));
        $this->assertStringContainsString('<hr>', $this->render("a\n\n___\n\nb"));
    }

    public function testDashesFollowedByTextAreAListNotABreak(): void
    {
        $html = $this->render("- one\n- two");

        $this->assertStringNotContainsString('<hr>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
    }

    public function testBlockquote(): void
    {
        $html = $this->render("Given that:\n\n> an avatar appearing after one second instead of twelve.\n\nAgainst that:");

        $this->assertStringContainsString(
            "<blockquote>\n<p>an avatar appearing after one second instead of twelve.</p>\n</blockquote>",
            $html,
        );
    }

    public function testUnorderedList(): void
    {
        $html = $this->render("- alpha\n- beta\n- gamma");

        $this->assertStringContainsString("<ul>\n<li>alpha</li>\n<li>beta</li>\n<li>gamma</li>\n</ul>", $html);
    }

    public function testOrderedListKeepsItsStartingNumber(): void
    {
        $html = $this->render("8. eight\n9. nine\n10. ten");

        $this->assertStringContainsString('<ol start="8">', $html);
        $this->assertStringContainsString('<li>ten</li>', $html);
    }

    public function testOrderedListStartingAtOneNeedsNoStartAttribute(): void
    {
        $this->assertStringContainsString("<ol>\n", $this->render("1. one\n2. two"));
    }

    public function testNestedListUnderAnOrderedItem(): void
    {
        // The indentation the roadmap actually uses: three spaces to the bullet,
        // five to its continuation lines.
        $html = $this->render(<<<'MD'
            7. **Marketplace.** The install path had holes:

               - **Zip Slip.** Extraction now validates every entry
                 before writing it.
               - **Missing authorization.** The controller claimed a
                 guard; nothing did.

               Covered by a new end-to-end test.
            MD);

        $this->assertStringContainsString('<ol start="7">', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<strong>Zip Slip.</strong>', $html);
        $this->assertStringContainsString('<p>Covered by a new end-to-end test.</p>', $html);
        // The nested list closes before the item does.
        $this->assertMatchesRegularExpression('#</ul>.*?</li>.*?</ol>#s', $html);
    }

    public function testDeeplyNestedUnorderedLists(): void
    {
        $html = $this->render("- one\n  - one a\n    - one a i\n- two");

        $this->assertSame(3, substr_count($html, '<ul>'));
        $this->assertSame(3, substr_count($html, '</ul>'));
        $this->assertStringContainsString('one a i', $html);
    }

    public function testTightListItemsAreNotWrappedInParagraphs(): void
    {
        $html = $this->render("- one\n- two");

        $this->assertStringNotContainsString('<p>', $html);
    }

    public function testLooseListItemsAreWrappedInParagraphs(): void
    {
        $html = $this->render("- one\n\n- two");

        $this->assertStringContainsString('<p>one</p>', $html);
        $this->assertStringContainsString('<p>two</p>', $html);
    }

    public function testFenceInsideAListItem(): void
    {
        $html = $this->render("1. Run it:\n\n   ```bash\n   php bin/click-seed.php\n   ```\n\n2. Done.");

        $this->assertStringContainsString('<pre><code class="language-bash">php bin/click-seed.php</code></pre>', $html);
        $this->assertStringContainsString('<li>', $html);
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    public function testTableWithHeaderRow(): void
    {
        $html = $this->render(<<<'MD'
            | Policy | Behaviour |
            |---|---|
            | `manual` | Never checks. |
            | **`security`** (default) | **Installs security releases.** |
            MD);

        $this->assertStringContainsString('<div class="table-wrap">', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<th>Policy</th><th>Behaviour</th>', $html);
        $this->assertStringContainsString('<td><code>manual</code></td>', $html);
        $this->assertStringContainsString('<strong><code>security</code></strong> (default)', $html);
    }

    public function testTableAlignmentRow(): void
    {
        $html = $this->render("| a | b | c |\n| :--- | :---: | ---: |\n| 1 | 2 | 3 |");

        $this->assertStringContainsString('<th class="align-left">a</th>', $html);
        $this->assertStringContainsString('<th class="align-center">b</th>', $html);
        $this->assertStringContainsString('<th class="align-right">c</th>', $html);
    }

    public function testPipeInsideACodeSpanDoesNotSplitTheCell(): void
    {
        $html = $this->render("| Pattern | Note |\n|---|---|\n| `a \\| b` | one cell |");

        $this->assertStringContainsString('<td><code>a \\| b</code></td><td>one cell</td>', $html);
    }

    public function testATableRowWithoutADelimiterIsJustAParagraph(): void
    {
        $html = $this->render("| not a table\nstill prose");

        $this->assertStringNotContainsString('<table>', $html);
    }

    // ------------------------------------------------------------------
    // Heading anchors and slugs
    // ------------------------------------------------------------------

    public function testHeadingCarriesAnIdAndAnAnchorLink(): void
    {
        $html = $this->render('### The render cache');

        $this->assertStringContainsString('<h3 id="the-render-cache">', $html);
        $this->assertStringContainsString('<a class="anchor" href="#the-render-cache"', $html);
        $this->assertStringContainsString('aria-label="Link to this section: The render cache"', $html);
    }

    #[DataProvider('headingsAndSlugs')]
    public function testSlugsStripPunctuation(string $heading, string $expected): void
    {
        $document = $this->renderer->render('## ' . $heading);

        $this->assertSame($expected, $document->headings[0]['id']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function headingsAndSlugs(): array
    {
        return [
            'plain' => ['The render cache', 'the-render-cache'],
            'em dash' => ['Route 1 — Docker', 'route-1-docker'],
            'code span' => ['`Core\\Application`', 'coreapplication'],
            'comma' => ['What this is modelled on, and where the model stops', 'what-this-is-modelled-on-and-where-the-model-stops'],
            'slash' => ['NoSQL / document stores — a later idea', 'nosql-document-stores-a-later-idea'],
            'quotes' => ['rest-api — DONE, but see "Management API"', 'rest-api-done-but-see-management-api'],
            'trailing colon' => ['Transport: polling, not WebSockets', 'transport-polling-not-websockets'],
            'numbered' => ['1. Change the password', '1-change-the-password'],
            'bold' => ['**Where things live**', 'where-things-live'],
            'accented' => ['Über die Größe', 'über-die-größe'],
            'punctuation only' => ['?!', 'section'],
        ];
    }

    public function testRepeatedHeadingsGetDistinctIds(): void
    {
        $document = $this->renderer->render("## What holds\n\n## What holds\n\n## What holds");

        $this->assertSame(
            ['what-holds', 'what-holds-2', 'what-holds-3'],
            array_column($document->headings, 'id'),
        );
    }

    public function testEachDocumentStartsWithAFreshSlugTable(): void
    {
        $this->renderer->render('## What holds');
        $second = $this->renderer->render('## What holds');

        $this->assertSame('what-holds', $second->headings[0]['id']);
    }

    public function testTitleComesFromTheFirstLevelOneHeading(): void
    {
        $document = $this->renderer->render("# Installing Click CMS\n\n## Requirements");

        $this->assertSame('Installing Click CMS', $document->title);
    }

    public function testHeadingInsideAFenceIsNotAHeading(): void
    {
        $document = $this->renderer->render("```bash\n# replace 1.0.0 with the current release\ncurl -LO x\n```");

        $this->assertSame([], $document->headings);
        $this->assertStringContainsString('# replace 1.0.0', $document->html);
    }

    public function testOutlineSkipsTheTitleAndDeepHeadings(): void
    {
        $document = $this->renderer->render("# Title\n\n## Two\n\n### Three\n\n#### Four");

        $this->assertSame(['Two', 'Three'], array_column($document->outline(), 'text'));
    }

    public function testHeadingTextInTheOutlineIsPlainNotMarkup(): void
    {
        $document = $this->renderer->render('## Why `sequence` and `expires` exist');

        $this->assertSame('Why sequence and expires exist', $document->headings[0]['text']);
    }

    // ------------------------------------------------------------------
    // Determinism
    // ------------------------------------------------------------------

    public function testRenderingIsDeterministic(): void
    {
        $markdown = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/core.md');

        $first = (new MarkdownRenderer())->render($markdown)->html;
        $second = (new MarkdownRenderer())->render($markdown)->html;

        $this->assertSame($first, $second);
    }

    /** @return list<string> The distinct tag names present, in order of first appearance. */
    private function tagNames(string $html): array
    {
        preg_match_all('/<([a-z][a-z0-9]*)/i', $html, $matches);

        return array_values(array_unique($matches[1]));
    }
}
