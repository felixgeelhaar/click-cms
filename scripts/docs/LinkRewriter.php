<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * Turns a Markdown link destination into a URL that works on the built site.
 *
 * The docs are written to be read in the repository, so their links point at
 * repository paths: `docs/core.md` from the README, `core.md#the-render-cache`
 * from a sibling page, `../docker/apache-vhost.conf` from `docs/install.md`.
 * Published as-is, all three are dead — and a dead cross-reference in a document
 * whose whole argument is "see the other page for why" is worse than no link.
 *
 * Three outcomes, in order:
 *
 *   - An absolute URL (`https:`, `mailto:`, protocol-relative) is left exactly
 *     as written. The docs link out to theupdateframework.io and to GitHub
 *     releases, and rewriting those would be vandalism.
 *   - A path that resolves to a page of this site becomes that page's clean URL,
 *     expressed **relatively** so the site works whether GitHub Pages serves it
 *     at the domain root or under a project path. Any `#fragment` is carried
 *     across unchanged, which is what makes `core.md#the-render-cache` resolve.
 *   - Anything else is a real file in the repository that is not part of the
 *     site — a vhost config, a workflow, a script. It becomes a link to that
 *     file on GitHub. Leaving it relative would 404; dropping the link would
 *     hide the fact that the file exists.
 */
final class LinkRewriter
{
    /**
     * @param array<string, string> $pages Repository-relative Markdown path
     *        (`docs/core.md`) to site path relative to the site root (`core/`).
     * @param string $blobBase Base URL for a file in the repository, ending in
     *        a slash — `https://github.com/owner/repo/blob/main/`.
     */
    public function __construct(
        private readonly array $pages,
        private readonly string $blobBase,
    ) {
    }

    /**
     * @param string $sourceDirectory Repository-relative directory of the
     *        document being rendered: `''` for the README, `docs` for a page.
     * @param int $depth How many directories deep the output file sits below the
     *        site root, which is how many `../` a site-relative link needs.
     */
    public function rewrite(string $destination, string $sourceDirectory, int $depth): string
    {
        if ($destination === '') {
            return $destination;
        }

        if (str_starts_with($destination, '#')) {
            return $destination;
        }

        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $destination) === 1) {
            return $destination;
        }

        [$path, $fragment] = $this->split($destination);

        if ($path === '') {
            return $destination;
        }

        $resolved = $this->resolve($sourceDirectory, $path);

        if (isset($this->pages[$resolved])) {
            $prefix = str_repeat('../', $depth);
            $url = $prefix . $this->pages[$resolved];

            return ($url === '' ? './' : $url) . $fragment;
        }

        return $this->blobBase . $resolved . $fragment;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $destination): array
    {
        $hash = strpos($destination, '#');
        if ($hash === false) {
            return [$destination, ''];
        }

        return [substr($destination, 0, $hash), substr($destination, $hash)];
    }

    /** Resolves `../` and `./` against the source directory, without touching disk. */
    private function resolve(string $sourceDirectory, string $path): string
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
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }

        return implode('/', $stack);
    }
}
