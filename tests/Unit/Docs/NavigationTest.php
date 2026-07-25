<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use ClickCms\Tools\Docs\Navigation;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/docs/bootstrap.php';

/**
 * The navigation manifest decides what the sidebar looks like, and its two
 * interesting cases are both about documents nobody remembered to register.
 *
 * A page written on a branch and left out of the manifest must still appear —
 * a doc that silently vanishes from the navigation is invisible, and nobody
 * finds out until a reader says they could not find it. A manifest entry with
 * no file behind it is the opposite problem and the opposite answer: the
 * editor-facing pages are being written now, and a nav that lists a page that
 * does not exist yet would be a promise the site cannot keep.
 */
final class NavigationTest extends TestCase
{
    public function testGroupsAppearInManifestOrderNotAlphabetically(): void
    {
        $groups = (new Navigation())->groups([
            'docs/backlog.md',
            'docs/core.md',
            'docs/install.md',
            'docs/updates.md',
            'README.md',
        ]);

        $labels = array_column($groups, 'label');
        $this->assertSame(['Start here', 'Running a site', 'Building on it'], $labels);
    }

    public function testPagesInsideAGroupKeepManifestOrder(): void
    {
        // Alphabetically this would be backup, install, updates.
        $groups = (new Navigation())->groups([
            'docs/backup.md',
            'docs/updates.md',
            'docs/install.md',
        ]);

        $this->assertSame(
            ['docs/install.md', 'docs/updates.md', 'docs/backup.md'],
            $this->group($groups, 'Running a site'),
        );
    }

    public function testAPageNobodyRegisteredStillAppearsUnderAFallbackHeading(): void
    {
        $groups = (new Navigation())->groups(['docs/install.md', 'docs/telemetry.md']);

        $this->assertSame(['docs/telemetry.md'], $this->group($groups, Navigation::FALLBACK_GROUP));
    }

    public function testTheFallbackGroupComesLastAndIsSorted(): void
    {
        $groups = (new Navigation())->groups(['docs/zebra.md', 'docs/aardvark.md', 'docs/core.md']);

        $this->assertSame(Navigation::FALLBACK_GROUP, $groups[count($groups) - 1]['label']);
        $this->assertSame(
            ['docs/aardvark.md', 'docs/zebra.md'],
            $this->group($groups, Navigation::FALLBACK_GROUP),
        );
    }

    /** The editor-facing pages are being written in parallel; the nav waits. */
    public function testAManifestEntryWithNoFileIsSkippedQuietly(): void
    {
        $groups = (new Navigation())->groups(['docs/install.md']);

        $this->assertSame(['Running a site'], array_column($groups, 'label'));
    }

    public function testEveryPageGivenIsPlacedExactlyOnce(): void
    {
        $sources = ['README.md', 'docs/core.md', 'docs/install.md', 'docs/unregistered.md'];

        $placed = [];
        foreach ((new Navigation())->groups($sources) as $group) {
            foreach ($group['sources'] as $source) {
                $placed[] = $source;
            }
        }

        sort($sources);
        sort($placed);
        $this->assertSame($sources, $placed);
    }

    /**
     * @param list<array{label: string, sources: list<string>}> $groups
     * @return list<string>
     */
    private function group(array $groups, string $label): array
    {
        foreach ($groups as $group) {
            if ($group['label'] === $label) {
                return $group['sources'];
            }
        }

        $this->fail("No navigation group labelled {$label}.");
    }
}
