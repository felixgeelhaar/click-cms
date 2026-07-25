<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * The result of rendering one Markdown file: the HTML, the document title taken
 * from its first level-1 heading, and the heading outline the page's table of
 * contents is built from.
 */
final class RenderedDocument
{
    /**
     * @param list<array{level: int, text: string, id: string}> $headings
     */
    public function __construct(
        public readonly string $html,
        public readonly ?string $title,
        public readonly array $headings,
    ) {
    }

    /**
     * The headings a table of contents should show. The level-1 heading is the
     * page title and is already displayed above the contents, so listing it
     * again is noise; anything below level 3 is too fine-grained to navigate by.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function outline(): array
    {
        return array_values(array_filter(
            $this->headings,
            static fn (array $heading): bool => $heading['level'] === 2 || $heading['level'] === 3,
        ));
    }
}
