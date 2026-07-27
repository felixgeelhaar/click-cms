<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use ClickCms\Tools\Docs\SiteBuilder;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/docs/bootstrap.php';

/**
 * The builder's job is narrow and its failure modes are all quiet ones: a page
 * that exists in the repository but not on the site, a cross-reference that
 * 404s, a `.nojekyll` that is missing so GitHub Pages runs Jekyll and drops
 * files, output that churns on every rebuild so no diff of the published site
 * is readable.
 *
 * Most of this runs against a fixture repository rather than the real one, so
 * the assertions stay true as the docs are edited. The last few deliberately do
 * run against the real docs, because "the site builds" is a claim about *these*
 * documents.
 */
final class SiteBuilderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/click-cms-docs-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/repo/docs', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // Discovery and URLs
    // ------------------------------------------------------------------

    /**
     * A sidebar label the generic transform cannot produce.
     *
     * Labels are `ucfirst` over the filename with hyphens turned into spaces,
     * which reads correctly for `visual-builder` and cannot possibly be right
     * for an initialism: shipping `docs/sso.md` put **"Sso"** in the navigation
     * of a published site.
     *
     * The document's own `# ` heading is not the answer — that is a title
     * ("Installing Click CMS") where a sidebar wants a name ("Install"), which
     * is exactly why `Page::label` exists apart from `RenderedDocument::title`.
     * Nor is a cleverer transform: nothing in `multi-site` distinguishes a
     * hyphen inside a word from `visual-builder`'s hyphen between two. It is
     * per-document knowledge, so it is stated per document.
     */
    public function testAnInitialismIsNamedProperlyInTheSidebar(): void
    {
        $this->fixture();
        file_put_contents(
            $this->workspace . '/repo/docs/sso.md',
            "# Single sign-on\n\nSigning in with an account you already have.\n"
        );

        $this->build();
        $html = $this->read('sso/index.html');

        $this->assertStringContainsString('>SSO</a>', $html);
        $this->assertStringNotContainsString('>Sso</a>', $html);
    }

    /**
     * A hyphen inside a word survives, where one between words becomes a space.
     * The filename alone cannot tell those apart, which is the whole reason the
     * overrides exist.
     */
    public function testAHyphenatedWordKeepsItsHyphen(): void
    {
        $this->fixture();
        file_put_contents(
            $this->workspace . '/repo/docs/multi-site.md',
            "# Running more than one site\n\nOne installation, many sites.\n"
        );

        $this->build();

        $this->assertStringContainsString('>Multi-site</a>', $this->read('multi-site/index.html'));
    }

    /**
     * Everything the transform already handles must go on being handled by it.
     * An override list that had to name every page would be a manifest nobody
     * keeps current, and `visual-builder` is the case it gets right.
     */
    public function testAnOrdinaryNameStillComesFromTheFilename(): void
    {
        $this->fixture();
        file_put_contents(
            $this->workspace . '/repo/docs/visual-builder.md',
            "# The visual builder\n\nA node tree.\n"
        );

        $this->build();

        $this->assertStringContainsString('>Visual builder</a>', $this->read('visual-builder/index.html'));
    }

    /**
     * The override list names documents that may not exist — a page written on
     * a branch, or one since renamed. It must not be able to fail a build, for
     * the same reason `Navigation` skips a manifest entry with no file behind
     * it.
     */
    public function testAnOverrideForAMissingDocumentIsHarmless(): void
    {
        $this->fixture();

        $written = $this->build();

        $this->assertContains('index.html', $written);
    }

    public function testEveryMarkdownFileBecomesAPage(): void
    {
        $this->fixture();
        $written = $this->build();

        $this->assertContains('index.html', $written);
        $this->assertContains('install/index.html', $written);
        $this->assertContains('core/index.html', $written);
    }

    /**
     * The docs directory is globbed, never listed in code. A page written on a
     * branch by somebody else has to appear without this file being touched —
     * the navigation manifest sets the order, and a document missing from it is
     * unranked, never invisible.
     */
    public function testADocumentNobodyRegisteredStillAppears(): void
    {
        $this->fixture();
        file_put_contents(
            $this->workspace . '/repo/docs/telemetry.md',
            "# Telemetry\n\nA page nobody added to the navigation manifest.\n",
        );

        $written = $this->build();
        $html = $this->read('core/index.html');

        $this->assertContains('telemetry/index.html', $written);
        $this->assertStringContainsString(
            '<li><a href="../telemetry/">Telemetry</a></li>',
            $html,
        );
        $this->assertStringContainsString('>More</p>', $html, 'The fallback heading is missing.');
    }

    /**
     * The manifest names the editor-facing pages before they are written. An
     * entry with no file behind it is a page not written yet, not an error.
     */
    public function testAManifestEntryWithNoFileDoesNotBreakTheBuild(): void
    {
        $this->fixture();
        $written = $this->build();

        $this->assertContains('index.html', $written);
        $this->assertStringNotContainsString('>Pages</a>', $this->read('index.html'));
    }

    public function testUrlsAreClean(): void
    {
        $this->fixture();
        $this->build();

        $this->assertFileExists($this->out() . '/install/index.html');
        $this->assertFileDoesNotExist($this->out() . '/install.html');
    }

    // ------------------------------------------------------------------
    // Navigation and contents
    // ------------------------------------------------------------------

    public function testNavigationListsEveryPageOnEveryPage(): void
    {
        $this->fixture();
        $written = $this->build();

        foreach ($written as $path) {
            if (!str_ends_with($path, '.html')) {
                continue;
            }
            $html = $this->read($path);
            foreach (['Overview', 'Install', 'Core'] as $label) {
                $this->assertStringContainsString(">{$label}</a>", $html, "{$path} is missing {$label}");
            }
        }
    }

    public function testTheCurrentPageIsMarkedOnceAndOnlyOnce(): void
    {
        $this->fixture();
        $this->build();
        $html = $this->read('install/index.html');

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString(
            '<li><a class="current" aria-current="page" href="../install/">Install</a></li>',
            $html,
        );
    }

    public function testNavigationLinksAreRelativeToTheCurrentPage(): void
    {
        $this->fixture();
        $this->build();

        $this->assertStringContainsString('<li><a href="install/">Install</a></li>', $this->read('index.html'));
        $this->assertStringContainsString('<li><a href="../core/">Core</a></li>', $this->read('install/index.html'));
        $this->assertStringContainsString('href="style.css"', $this->read('index.html'));
        $this->assertStringContainsString('href="../style.css"', $this->read('install/index.html'));
    }

    public function testNavigationIsGroupedByAudienceInManifestOrder(): void
    {
        $this->fixture();
        $this->build();
        $html = $this->read('core/index.html');

        preg_match_all('/<p class="nav-heading" id="nav-[a-z-]+">([^<]+)<\/p>/', $html, $matches);
        $this->assertSame(['Start here', 'Running a site', 'Building on it'], $matches[1]);
    }

    /**
     * The group labels name their lists for assistive technology without being
     * headings — a level-2 heading here would sit above the page's own h1.
     */
    public function testGroupLabelsNameTheirListsWithoutBecomingHeadings(): void
    {
        $this->fixture();
        $this->build();
        $html = $this->read('core/index.html');

        $this->assertStringContainsString(
            '<p class="nav-heading" id="nav-running-a-site">Running a site</p>',
            $html,
        );
        $this->assertStringContainsString('<ul aria-labelledby="nav-running-a-site">', $html);
        $this->assertStringNotContainsString('<h2>Running a site</h2>', $html);
    }

    public function testEachPageHasATableOfContentsFromItsOwnHeadings(): void
    {
        $this->fixture();
        $this->build();
        $html = $this->read('core/index.html');

        $this->assertStringContainsString('aria-label="On this page"', $html);
        $this->assertStringContainsString('<a href="#the-test">The test</a>', $html);
        $this->assertStringContainsString('<a href="#the-render-cache">The render cache</a>', $html);
        // The h1 is the page title, already on screen; repeating it is noise.
        $this->assertStringNotContainsString('>What belongs in core</a>', $html);
    }

    public function testAPageWithTooFewHeadingsGetsNoTableOfContents(): void
    {
        $this->fixture();
        file_put_contents($this->workspace . '/repo/docs/short.md', "# Short\n\nOne paragraph.\n");
        $this->build();

        $this->assertStringNotContainsString('aria-label="On this page"', $this->read('short/index.html'));
    }

    // ------------------------------------------------------------------
    // Accessibility and self-containment
    // ------------------------------------------------------------------

    public function testEveryPageHasTheAccessibilityScaffolding(): void
    {
        $this->fixture();
        $written = $this->build();

        foreach ($written as $path) {
            if (!str_ends_with($path, '.html')) {
                continue;
            }
            $html = $this->read($path);
            $this->assertStringContainsString('<html lang="en">', $html, $path);
            $this->assertStringContainsString('class="skip-link" href="#content"', $html, $path);
            $this->assertStringContainsString('<main id="content"', $html, $path);
            $this->assertStringContainsString('<nav class="pages" aria-label=', $html, $path);
            $this->assertSame(1, substr_count($html, '<h1'), "{$path} must have exactly one h1");
        }
    }

    /**
     * A level-2 heading above the page's own level-1 would break the document
     * outline. The sidebar labels are paragraphs for exactly that reason, and
     * this is the assertion that keeps them that way.
     */
    public function testNoHeadingPrecedesThePageTitle(): void
    {
        $this->fixture();
        $this->build();
        $html = $this->read('core/index.html');

        preg_match('/<h([1-6])/', $html, $first);
        $this->assertSame('1', $first[1]);
    }

    public function testTheSiteMakesNoExternalRequests(): void
    {
        $this->fixture();
        $written = $this->build();

        foreach ($written as $path) {
            if (!str_ends_with($path, '.html')) {
                continue;
            }
            $html = $this->read($path);
            $this->assertStringNotContainsString('<img', $html, $path);
            $this->assertStringNotContainsString('<script', $html, $path);
            $this->assertDoesNotMatchRegularExpression('#<link[^>]+href="https?://#', $html, $path);
            $this->assertStringNotContainsString('@import', $html, $path);
        }

        $css = $this->read('style.css');
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('url(http', $css);
    }

    public function testTheStylesheetDefinesBothColourSchemes(): void
    {
        $this->fixture();
        $this->build();
        $css = $this->read('style.css');

        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
        $this->assertStringContainsString('--paper', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('overflow-x: auto', $css);
    }

    // ------------------------------------------------------------------
    // Screenshots
    // ------------------------------------------------------------------

    public function testAScreenshotIsCopiedIntoTheSiteAndShownAsAPicture(): void
    {
        $this->fixture();
        $this->screenshot();
        $written = $this->build();

        $this->assertContains('assets/docs/images/dashboard.png', $written);
        $this->assertStringContainsString(
            '<img src="../assets/docs/images/dashboard.png" alt="The page list"'
                . ' width="640" height="400" loading="lazy">',
            $this->read('install/index.html'),
        );
    }

    public function testACopiedScreenshotIsTheSameFileByteForByte(): void
    {
        $this->fixture();
        $this->screenshot();
        $this->build();

        $this->assertSame(
            hash_file('sha256', $this->workspace . '/repo/docs/images/dashboard.png'),
            hash_file('sha256', $this->out() . '/assets/docs/images/dashboard.png'),
        );
    }

    public function testAScreenshotOnItsOwnBecomesACaptionedFigure(): void
    {
        $this->fixture();
        $this->screenshot();
        $this->build();

        $this->assertStringContainsString('<figure class="image">', $this->read('install/index.html'));
        $this->assertStringContainsString(
            '<figcaption>The page list</figcaption>',
            $this->read('install/index.html'),
        );
    }

    /** The badge row in the README must not start fetching anything. */
    public function testRemoteBadgesStillRenderAsLinksOnASiteThatHasImages(): void
    {
        $this->fixture();
        $this->screenshot();
        file_put_contents(
            $this->workspace . '/repo/README.md',
            "# Click CMS\n\n![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4)\n\nA flat-file CMS.\n",
        );
        $this->build();
        $html = $this->read('index.html');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<a class="badge" href="https://img.shields.io/', $html);
    }

    public function testAnImageWithoutAltTextFailsTheBuildNamingTheFileAndTheImage(): void
    {
        $this->fixture();
        $this->screenshot('![](images/dashboard.png)');

        $this->expectExceptionMessageMatches('#docs/install\.md.*images/dashboard\.png#s');
        $this->build();
    }

    public function testAScreenshotThatIsNotThereFailsTheBuild(): void
    {
        $this->fixture();
        file_put_contents(
            $this->workspace . '/repo/docs/install.md',
            "# Installing Click CMS\n\n![The page list](images/absent.png)\n",
        );

        $this->expectExceptionMessageMatches('#docs/install\.md.*docs/images/absent\.png#s');
        $this->build();
    }

    public function testTwoBuildsWithScreenshotsAreByteIdentical(): void
    {
        $this->fixture();
        $this->screenshot();

        $first = $this->workspace . '/out-1';
        $second = $this->workspace . '/out-2';
        $builder = new SiteBuilder($this->workspace . '/repo');
        $builder->build($first);
        $builder->build($second);

        $this->assertSame($this->fingerprint($first), $this->fingerprint($second));
    }

    // ------------------------------------------------------------------
    // GitHub Pages requirements
    // ------------------------------------------------------------------

    public function testNojekyllIsWritten(): void
    {
        $this->fixture();
        $written = $this->build();

        $this->assertContains('.nojekyll', $written);
        $this->assertSame('', $this->read('.nojekyll'));
    }

    public function testNotFoundPageUsesAbsoluteLinksFromTheGivenBasePath(): void
    {
        $this->fixture();
        $this->build('/click-cms/');
        $html = $this->read('404.html');

        $this->assertStringContainsString('<h1>Not found</h1>', $html);
        $this->assertStringContainsString('href="/click-cms/style.css"', $html);
        $this->assertStringContainsString('href="/click-cms/install/"', $html);
        $this->assertStringContainsString('href="/click-cms/"', $html);
    }

    public function testNotFoundPageDefaultsToTheDomainRoot(): void
    {
        $this->fixture();
        $this->build();
        $html = $this->read('404.html');

        $this->assertStringContainsString('href="/style.css"', $html);
        $this->assertStringContainsString('href="/install/"', $html);
    }

    // ------------------------------------------------------------------
    // Determinism
    // ------------------------------------------------------------------

    public function testTwoBuildsOfUnchangedInputAreByteIdentical(): void
    {
        $this->fixture();

        $first = $this->workspace . '/out-1';
        $second = $this->workspace . '/out-2';
        $builder = new SiteBuilder($this->workspace . '/repo');
        $builder->build($first);
        $builder->build($second);

        $this->assertSame($this->fingerprint($first), $this->fingerprint($second));
    }

    public function testTheRealDocumentationBuildsDeterministically(): void
    {
        $repository = dirname(__DIR__, 3);
        $first = $this->workspace . '/real-1';
        $second = $this->workspace . '/real-2';

        $builder = new SiteBuilder($repository);
        $builder->build($first);
        $builder->build($second);

        $this->assertSame($this->fingerprint($first), $this->fingerprint($second));
    }

    // ------------------------------------------------------------------
    // The real documentation
    // ------------------------------------------------------------------

    public function testCrossDocumentAnchorsResolveInTheRealSite(): void
    {
        $repository = dirname(__DIR__, 3);
        $output = $this->workspace . '/real';
        $written = (new SiteBuilder($repository))->build($output);

        $pages = array_values(array_filter($written, static fn (string $p): bool => str_ends_with($p, 'index.html')));
        $ids = [];
        foreach ($pages as $path) {
            $html = (string) file_get_contents($output . '/' . $path);
            preg_match_all('/<h[1-6] id="([^"]+)"/', $html, $matches);
            $ids[dirname($path) === '.' ? '' : dirname($path) . '/'] = $matches[1];
        }

        $checked = 0;
        foreach ($pages as $path) {
            $from = dirname($path) === '.' ? '' : dirname($path) . '/';
            $html = (string) file_get_contents($output . '/' . $path);
            preg_match_all('/href="((?:\.\.\/)?[a-z0-9-]+\/)#([^"]+)"/', $html, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $target = $this->resolve($from, $match[1]);
                $this->assertArrayHasKey($target, $ids, "{$path} links to a page that does not exist: {$match[1]}");
                $this->assertContains(
                    $match[2],
                    $ids[$target],
                    "{$path} links to #{$match[2]} in {$target}, which has no such heading",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'No cross-document anchor links were found to check.');
    }

    public function testTheRealSiteHasNoUnresolvedRelativeLinks(): void
    {
        $repository = dirname(__DIR__, 3);
        $output = $this->workspace . '/real';
        $written = (new SiteBuilder($repository))->build($output);

        foreach ($written as $path) {
            if (!str_ends_with($path, '.html')) {
                continue;
            }
            $html = (string) file_get_contents($output . '/' . $path);
            // Absolute links into the repository are meant to end in `.md`;
            // a *relative* one is a link that was never rewritten and 404s.
            preg_match_all('/href="(?!https?:|mailto:)([^"]*\.md(?:#[^"]*)?)"/', $html, $matches);
            $this->assertSame([], $matches[1], "{$path} still links to a .md file");
        }
    }

    public function testTheRealSiteRendersHtmlInsideCodeAsVisibleText(): void
    {
        $repository = dirname(__DIR__, 3);
        $output = $this->workspace . '/real';
        (new SiteBuilder($repository))->build($output);

        $html = (string) file_get_contents($output . '/visual-builder/index.html');

        $this->assertStringContainsString('<code>&lt;section&gt;</code>', $html);
        // If escaping had failed, a real <section> would be open in the article.
        $this->assertSame(
            substr_count($html, '<section'),
            0,
            'A code span leaked a real element into the page.',
        );
    }

    public function testAnEmptyRepositoryIsRefusedRatherThanProducingAnEmptySite(): void
    {
        mkdir($this->workspace . '/empty', 0o755, true);

        $this->expectExceptionMessageMatches('/No Markdown found/');
        (new SiteBuilder($this->workspace . '/empty'))->build($this->workspace . '/nothing');
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    private function fixture(): void
    {
        file_put_contents($this->workspace . '/repo/README.md', <<<'MD'
            # Click CMS

            A flat-file CMS.

            ## Documentation

            - [Installation](docs/install.md)
            - [What belongs in core](docs/core.md)

            ## License

            MIT
            MD);

        file_put_contents($this->workspace . '/repo/docs/install.md', <<<'MD'
            # Installing Click CMS

            ## Requirements

            PHP 8.1 or newer.

            ## Going to production

            An Apache virtual host to copy is in [`docker/apache-vhost.conf`](../docker/apache-vhost.conf).

            Pages are cached; see [the render cache](core.md#the-render-cache).
            MD);

        file_put_contents($this->workspace . '/repo/docs/core.md', <<<'MD'
            # What belongs in core

            ## The test

            Something belongs in core when the CMS is incoherent without it.

            ## Core capabilities

            ### The render cache

            Rendered pages are stored as flat files.
            MD);
    }

    /**
     * Adds a screenshot to the fixture repository and references it from
     * `docs/install.md`, the way a page written around a picture would.
     */
    private function screenshot(string $reference = '![The page list](images/dashboard.png)'): void
    {
        mkdir($this->workspace . '/repo/docs/images', 0o755, true);
        file_put_contents(
            $this->workspace . '/repo/docs/images/dashboard.png',
            ImageLibraryTest::pngBytes(640, 400),
        );
        file_put_contents(
            $this->workspace . '/repo/docs/install.md',
            "# Installing Click CMS\n\n## Requirements\n\nPHP 8.1 or newer.\n\n{$reference}\n",
        );
    }

    /** @return list<string> */
    private function build(string $basePath = '/'): array
    {
        return (new SiteBuilder(
            $this->workspace . '/repo',
            SiteBuilder::DEFAULT_REPOSITORY,
            'main',
            $basePath,
        ))->build($this->out());
    }

    private function out(): string
    {
        return $this->workspace . '/out';
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents($this->out() . '/' . $relativePath);
    }

    /** A content hash over every file, so a diff of any byte fails the test. */
    private function fingerprint(string $directory): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $entries[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($entries);

        return json_encode($entries, JSON_THROW_ON_ERROR);
    }

    private function resolve(string $from, string $href): string
    {
        if (!str_starts_with($href, '../')) {
            return rtrim($from, '/') === '' ? $href : $from . $href;
        }

        return substr($href, 3);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
