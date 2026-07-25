<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use ClickCms\Tools\Docs\LinkRewriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/docs/bootstrap.php';

/**
 * The docs are written to be read in the repository, so every internal link is
 * a repository path. Published unchanged, all of them are dead — and these are
 * documents whose argument frequently *is* "the other page explains why", so a
 * broken cross-reference loses the explanation, not just the click.
 *
 * The three outcomes are tested separately because they fail differently: a
 * mangled absolute URL sends a reader somewhere wrong, an unrewritten page link
 * 404s, and an unrewritten file link silently pretends the file is not there.
 */
final class LinkRewriterTest extends TestCase
{
    private const BLOB = 'https://github.com/felixgeelhaar/click-cms/blob/main/';

    private LinkRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new LinkRewriter(
            [
                'README.md' => '',
                'docs/core.md' => 'core/',
                'docs/install.md' => 'install/',
            ],
            self::BLOB,
        );
    }

    #[DataProvider('absoluteDestinations')]
    public function testAbsoluteDestinationsAreLeftExactlyAsWritten(string $destination): void
    {
        $this->assertSame($destination, $this->rewriter->rewrite($destination, 'docs', 1));
    }

    /** @return array<string, array{0: string}> */
    public static function absoluteDestinations(): array
    {
        return [
            'https' => ['https://theupdateframework.io/'],
            'http' => ['http://localhost:8080/admin/'],
            'mailto' => ['mailto:someone@example.test'],
            'protocol relative' => ['//example.test/x'],
            'release asset' => ['https://github.com/felixgeelhaar/click-cms/releases/download/v1.0.0/a.zip'],
        ];
    }

    public function testFragmentOnlyLinksAreLeftAlone(): void
    {
        $this->assertSame('#the-render-cache', $this->rewriter->rewrite('#the-render-cache', 'docs', 1));
    }

    public function testSiblingDocumentBecomesACleanUrl(): void
    {
        $this->assertSame('../core/', $this->rewriter->rewrite('core.md', 'docs', 1));
    }

    /**
     * The case the whole class exists for: `docs/install.md` links to
     * `core.md#the-render-cache`, and that anchor has to survive the move to a
     * directory URL or the reader lands at the top of a 466-line document.
     */
    public function testSiblingDocumentKeepsItsFragment(): void
    {
        $this->assertSame(
            '../core/#the-render-cache',
            $this->rewriter->rewrite('core.md#the-render-cache', 'docs', 1),
        );
    }

    public function testLinkFromTheLandingPageNeedsNoParentPrefix(): void
    {
        $this->assertSame('install/', $this->rewriter->rewrite('docs/install.md', '', 0));
    }

    public function testLinkBackToTheLandingPageResolvesToTheSiteRoot(): void
    {
        $this->assertSame('../', $this->rewriter->rewrite('../README.md', 'docs', 1));
        $this->assertSame('./', $this->rewriter->rewrite('README.md', '', 0));
    }

    public function testParentRelativePathToAFileBecomesAGitHubLink(): void
    {
        $this->assertSame(
            self::BLOB . 'docker/apache-vhost.conf',
            $this->rewriter->rewrite('../docker/apache-vhost.conf', 'docs', 1),
        );
    }

    public function testMarkdownThatIsNotAPageOfTheSiteBecomesAGitHubLink(): void
    {
        $this->assertSame(
            self::BLOB . 'CHANGELOG.md',
            $this->rewriter->rewrite('../CHANGELOG.md', 'docs', 1),
        );
    }

    public function testDotSegmentsAreResolvedWithoutTouchingTheDisk(): void
    {
        $this->assertSame('../core/', $this->rewriter->rewrite('./core.md', 'docs', 1));
        $this->assertSame('../core/', $this->rewriter->rewrite('../docs/core.md', 'docs', 1));
        $this->assertSame(
            self::BLOB . 'bin/click-seed.php',
            $this->rewriter->rewrite('../bin/../bin/click-seed.php', 'docs', 1),
        );
    }

    public function testRootRelativePathIsResolvedAgainstTheRepositoryRoot(): void
    {
        $this->assertSame('../install/', $this->rewriter->rewrite('/docs/install.md', 'docs', 1));
    }

    public function testEmptyDestinationIsLeftAlone(): void
    {
        $this->assertSame('', $this->rewriter->rewrite('', 'docs', 1));
    }
}
