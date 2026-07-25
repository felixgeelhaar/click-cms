<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * One page of the site, and the four facts every other part of the build needs
 * about it: where its Markdown comes from, where its HTML goes, what URL that
 * is, and how deep the URL sits so relative links can be counted back to the
 * site root.
 */
final class Page
{
    /**
     * @param string $source     Repository-relative Markdown path, `docs/core.md`.
     * @param string $outputPath Output-relative HTML path, `core/index.html`.
     * @param string $url        Site-root-relative URL, `core/` — empty for the
     *                           landing page, which is the site root itself.
     * @param int    $depth      Directories below the site root the output sits in.
     * @param string $label      Short name for the navigation.
     */
    public function __construct(
        public readonly string $source,
        public readonly string $outputPath,
        public readonly string $url,
        public readonly int $depth,
        public readonly string $label,
    ) {
    }

    /** The directory relative links in this document resolve against. */
    public function sourceDirectory(): string
    {
        $directory = dirname($this->source);

        return $directory === '.' ? '' : $directory;
    }
}
