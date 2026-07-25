<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * Decides whether an image belongs to the repository, and remembers the ones
 * that do so the build can copy them.
 *
 * There are exactly two kinds of image in these docs and they need opposite
 * treatment:
 *
 *   - **A screenshot in the repository.** `docs/images/dashboard.png`, written
 *     as a relative path from the page that shows it. It is a picture and the
 *     page is about it; it renders as an `<img>`, and the file is copied into
 *     the output so the site stays a directory that can be served from anywhere
 *     with nothing else present.
 *   - **A remote image.** The shields.io badges in the README, or anything else
 *     with a scheme, a protocol-relative prefix or a `data:` URI. The built site
 *     makes **no external requests of any kind** — that invariant is older than
 *     this file and does not bend for a badge — so these are not resolved here
 *     at all, and the renderer falls back to a labelled link.
 *
 * ## Where copies land
 *
 * Under `assets/`, keeping the repository-relative path: `docs/images/a.png`
 * becomes `assets/docs/images/a.png`. Repository paths are unique, so the output
 * path is unique too — no collision rule to remember, and no chance of two
 * screenshots called `dashboard.png` overwriting one another. `assets/` keeps
 * them out of the flat namespace of page URLs, which is where a document named
 * `docs.md` would otherwise land on top of them.
 *
 * ## Missing files
 *
 * A relative path to a file that is not there fails the build. It is the same
 * class of fault as a dead cross-reference, and the same argument applies: the
 * alternative is publishing a page with a hole in it and finding out from a
 * reader.
 */
final class ImageLibrary
{
    /** Every copied file sits below this, so `git clean` on the output is one path. */
    public const OUTPUT_PREFIX = 'assets/';

    /** @var array<string, string> Output-relative path => absolute source path. */
    private array $assets = [];

    private string $repositoryRoot;

    public function __construct(string $repositoryRoot)
    {
        $this->repositoryRoot = rtrim($repositoryRoot, '/');
    }

    /**
     * @param string $destination Image destination exactly as written in the
     *        Markdown, HTML-escaped by the renderer before it got here.
     * @param string $sourceDirectory Repository-relative directory of the
     *        document: `''` for the README, `docs` for a page.
     * @param int $depth How many directories below the site root the output file
     *        sits, which is how many `../` the `src` needs.
     * @return ImageAsset|null Null when the image is not a file of this
     *         repository, which is the renderer's signal to link rather than show.
     * @throws DocumentDefect When a repository path points at nothing.
     */
    public function resolve(string $destination, string $sourceDirectory, int $depth): ?ImageAsset
    {
        $destination = html_entity_decode($destination, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($destination === '' || $this->isRemote($destination)) {
            return null;
        }

        $relative = $this->resolvePath($sourceDirectory, $destination);
        if ($relative === null) {
            // A path that climbs out of the checkout is nothing this site can
            // publish; treat it the way a remote URL is treated.
            return null;
        }

        $absolute = $this->repositoryRoot . '/' . $relative;
        if (!is_file($absolute)) {
            throw new DocumentDefect(sprintf(
                'the image %s is not in the repository. A page that shows a screenshot '
                    . 'needs the screenshot committed alongside it.',
                $relative,
            ));
        }

        $real = realpath($absolute);
        $root = realpath($this->repositoryRoot);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $outputPath = self::OUTPUT_PREFIX . $relative;
        $this->assets[$outputPath] = $absolute;

        $dimensions = ImageDimensions::read($absolute);

        return new ImageAsset(
            str_repeat('../', $depth) . $outputPath,
            $dimensions['width'] ?? null,
            $dimensions['height'] ?? null,
        );
    }

    /**
     * Every file to copy, keyed by its path in the output and sorted, because a
     * build that writes the same files in a different order is not deterministic
     * in the sense that matters — the list is reported and compared.
     *
     * @return array<string, string>
     */
    public function assets(): array
    {
        $assets = $this->assets;
        ksort($assets, SORT_STRING);

        return $assets;
    }

    /** A scheme, a protocol-relative prefix or a data URI: not ours to publish. */
    private function isRemote(string $destination): bool
    {
        return preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $destination) === 1;
    }

    /**
     * Resolves `./` and `../` against the document's own directory. Returns null
     * if the path climbs above the repository root, which is the one case where
     * "it resolves inside the repo" is false.
     */
    private function resolvePath(string $sourceDirectory, string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            $segments = explode('/', trim($path, '/'));
        } else {
            $segments = array_merge(
                $sourceDirectory === '' ? [] : explode('/', trim($sourceDirectory, '/')),
                explode('/', $path),
            );
        }

        $stack = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($stack === []) {
                    return null;
                }
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }

        return $stack === [] ? null : implode('/', $stack);
    }
}
