<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The section catalogue in `config/sections/README.md` must list what actually
 * ships.
 *
 * It drifted the moment a design was added without being written down: `video`
 * shipped, rendered, and was documented for editors, while the developer-facing
 * table went on describing fifteen designs. A table that is *nearly* complete is
 * worse than none, because the missing row reads as "this does not exist"
 * rather than "this was not written down".
 *
 * Nothing here checks the wording of a row — only that every design has one and
 * that no row describes a design that was deleted.
 */
final class SectionCatalogueDocTest extends TestCase
{
    private const README = __DIR__ . '/../../../config/sections/README.md';
    private const DIR = __DIR__ . '/../../../config/sections';

    /** @return list<string> the ids of the designs that ship */
    private function shippedIds(): array
    {
        $ids = [];
        foreach (glob(self::DIR . '/*.json') ?: [] as $file) {
            $ids[] = basename($file, '.json');
        }
        sort($ids);

        return $ids;
    }

    /** @return list<string> the ids named in the "What ships here" table */
    private function documentedIds(): array
    {
        $markdown = (string) file_get_contents(self::README);

        $start = strpos($markdown, '## What ships here');
        self::assertNotFalse($start, 'The README has no "What ships here" section.');

        // The table runs to the next heading, so a later code block naming an id
        // is not mistaken for a row.
        $rest = substr($markdown, $start + 1);
        $end = strpos($rest, "\n## ");
        $table = $end === false ? $rest : substr($rest, 0, $end);

        preg_match_all('/^\| `([a-z0-9-]+)` \|/m', $table, $matches);
        $ids = $matches[1];
        sort($ids);

        return $ids;
    }

    public function testEverySectionThatShipsIsListed(): void
    {
        $missing = array_diff($this->shippedIds(), $this->documentedIds());

        self::assertSame(
            [],
            array_values($missing),
            'These section designs ship but are missing from the table in config/sections/README.md: '
            . implode(', ', $missing)
        );
    }

    public function testNothingIsListedThatNoLongerShips(): void
    {
        $stale = array_diff($this->documentedIds(), $this->shippedIds());

        self::assertSame(
            [],
            array_values($stale),
            'The table in config/sections/README.md lists designs that are not in config/sections: '
            . implode(', ', $stale)
        );
    }
}
