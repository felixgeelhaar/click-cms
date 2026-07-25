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
     * branch by somebody else has to appear without this file being touched.
     */
    public function testADocumentNobodyRegisteredStillAppears(): void
    {
        $this->fixture();
        file_put_contents(
            $this->workspace . '/repo/docs/backup.md',
            "# Backup and restore\n\nA ZIP export of content, media and drafts.\n",
        );

        $written = $this->build();

        $this->assertContains('backup/index.html', $written);
        $this->assertStringContainsString(
            '<li><a href="../backup/">Backup</a></li>',
            $this->read('core/index.html'),
        );
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
