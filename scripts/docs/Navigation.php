<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * The sidebar's shape: which documents belong together, what that group is
 * called, and what order everything comes in.
 *
 * This is the one place to edit. Adding a page to the site means dropping its
 * path into the list below — not touching the builder, not touching a template.
 * The list is the whole design and it is meant to be read top to bottom, because
 * that is the order a reader meets the pages in.
 *
 * ## Why groups at all
 *
 * A flat list of nine documents asks every reader to work out which four are
 * for them. These docs serve three different people — someone editing content
 * in the admin panel, someone running the installation, someone working on the
 * code — and the groups say so out loud. The labels are audiences, not topics.
 *
 * ## Two rules that matter more than the order
 *
 * **A document not listed here still appears**, under {@see self::FALLBACK_GROUP}.
 * A page that silently vanishes from the navigation because someone forgot to
 * register it is invisible, and nobody finds out until a reader says they could
 * not find it. Unlisted means unranked, never missing.
 *
 * **A listed document that does not exist yet is skipped**, silently. The
 * editor-facing pages below are being written now; naming them here before they
 * land is how the order is agreed in advance, and a navigation entry pointing at
 * a page that does not exist would be a promise the site cannot keep.
 */
final class Navigation
{
    /** Where a document nobody registered ends up. Last, and sorted. */
    public const FALLBACK_GROUP = 'More';

    /**
     * Group label => repository-relative Markdown paths, in the order they
     * should appear. Paths rather than names so an entry says exactly which
     * file it means and `grep` finds it from either direction.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'Start here' => [
            'README.md',
        ],
        // Written for whoever maintains the content, in the admin panel, with no
        // interest in how any of it works. In the order someone new to the panel
        // meets them: find your way around, change something, publish it.
        'Using your site' => [
            'docs/getting-started.md',
            'docs/editing.md',
            'docs/publishing.md',
            'docs/pictures.md',
        ],
        'Running a site' => [
            'docs/install.md',
            'docs/updates.md',
            'docs/backup.md',
        ],
        'Building on it' => [
            'docs/core.md',
            'docs/visual-builder.md',
            'docs/collaboration.md',
            'docs/practices.md',
            'docs/roadmap.md',
            'docs/backlog.md',
        ],
    ];

    /**
     * @param list<string> $sources Repository-relative Markdown paths that
     *        actually exist, in any order.
     * @return list<array{label: string, sources: list<string>}> Non-empty groups
     *         in manifest order, each holding its pages in manifest order.
     */
    public function groups(array $sources): array
    {
        $unplaced = array_fill_keys($sources, true);

        $groups = [];
        foreach (self::GROUPS as $label => $listed) {
            $present = [];
            foreach ($listed as $source) {
                if (isset($unplaced[$source])) {
                    $present[] = $source;
                    unset($unplaced[$source]);
                }
            }
            if ($present !== []) {
                $groups[] = ['label' => $label, 'sources' => $present];
            }
        }

        $leftover = array_keys($unplaced);
        sort($leftover, SORT_STRING);
        if ($leftover !== []) {
            $groups[] = ['label' => self::FALLBACK_GROUP, 'sources' => $leftover];
        }

        return $groups;
    }
}
