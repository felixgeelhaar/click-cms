<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

use RuntimeException;

/**
 * Builds the static documentation site.
 *
 * The input is the repository's own Markdown — `README.md` as the landing page
 * and every file matching `docs/*.md` as a page of its own. The directory is
 * globbed at build time, never enumerated in code: a doc added on a branch
 * appears on the site without anyone remembering to register it, which is the
 * only arrangement that survives contact with a project where documentation is
 * written by whoever is doing the work. {@see Navigation} then decides what
 * order those pages come in and which section of the sidebar they sit under —
 * grouping is a design decision and belongs in a list somebody can read, but it
 * can only ever *rank* a page, never hide one.
 *
 * ## URLs
 *
 * `README.md` becomes `index.html`; `docs/install.md` becomes
 * `install/index.html`, served at `install/`. Every link the site writes about
 * itself — navigation, stylesheet, cross-references — is **relative**, so the
 * built directory can be served from a domain root, from a project path, or out
 * of a local `php -S`, with no configuration and no base URL baked in. The one
 * exception is `404.html`, which the server may hand back for any path and so
 * cannot know how deep it is; its links are absolute and take the base path from
 * the `--base` option.
 *
 * ## Determinism
 *
 * Nothing here reads the clock, the environment or a random source. The page
 * order comes from the navigation manifest followed by a sort; heading ids come
 * from the heading text; copied images are copied byte for byte in sorted order;
 * the footer carries no build date. Two builds of unchanged input are
 * byte-identical, so `git diff` on the published site shows content changes and
 * nothing else. This project has been bitten by generated output that churned on
 * every run, and the fix is to have nothing to churn.
 */
final class SiteBuilder
{
    public const DEFAULT_REPOSITORY = 'https://github.com/felixgeelhaar/click-cms';

    private string $repositoryRoot;

    public function __construct(
        string $repositoryRoot,
        private readonly string $repositoryUrl = self::DEFAULT_REPOSITORY,
        private readonly string $branch = 'main',
        private readonly string $basePath = '/',
    ) {
        $this->repositoryRoot = rtrim($repositoryRoot, '/');
    }

    /**
     * @return list<string> Paths written, relative to the output directory,
     *         sorted — the caller reports them and the tests compare them.
     */
    public function build(string $outputDirectory): array
    {
        $outputDirectory = rtrim($outputDirectory, '/');
        $this->makeDirectory($outputDirectory);

        $pages = $this->discover();
        if ($pages === []) {
            throw new RuntimeException("No Markdown found under {$this->repositoryRoot}.");
        }

        $pageUrls = [];
        foreach ($pages as $page) {
            $pageUrls[$page->source] = $page->url;
        }
        $rewriter = new LinkRewriter($pageUrls, $this->repositoryUrl . '/blob/' . $this->branch . '/');
        $images = new ImageLibrary($this->repositoryRoot);

        $written = [];

        $rendered = [];
        foreach ($pages as $page) {
            $renderer = new MarkdownRenderer(
                fn (string $destination): string => $rewriter->rewrite(
                    $destination,
                    $page->sourceDirectory(),
                    $page->depth,
                ),
                fn (string $destination): ?ImageAsset => $images->resolve(
                    $destination,
                    $page->sourceDirectory(),
                    $page->depth,
                ),
            );
            $markdown = (string) file_get_contents($this->repositoryRoot . '/' . $page->source);
            try {
                $rendered[$page->source] = $renderer->render($markdown);
            } catch (DocumentDefect $defect) {
                // The renderer knows what is wrong; only the builder knows where.
                throw new RuntimeException($page->source . ': ' . $defect->getMessage(), 0, $defect);
            }
        }

        foreach ($pages as $page) {
            $document = $rendered[$page->source];
            $html = $this->layout($page, $document, $pages);
            $written[] = $this->write($outputDirectory, $page->outputPath, $html);
        }

        foreach ($images->assets() as $outputPath => $sourcePath) {
            $written[] = $this->copy($outputDirectory, $outputPath, $sourcePath);
        }

        $written[] = $this->write($outputDirectory, 'style.css', $this->stylesheet());
        $written[] = $this->write($outputDirectory, '404.html', $this->notFoundPage($pages));
        // GitHub Pages otherwise runs the input through Jekyll, which drops any
        // file or directory whose name begins with an underscore.
        $written[] = $this->write($outputDirectory, '.nojekyll', "");

        sort($written, SORT_STRING);

        return $written;
    }

    /**
     * Every Markdown file in the repository, in navigation order, each carrying
     * the group it belongs to.
     *
     * @return list<Page>
     */
    public function discover(): array
    {
        $sources = [];

        if (is_file($this->repositoryRoot . '/README.md')) {
            $sources[] = 'README.md';
        }

        $docs = glob($this->repositoryRoot . '/docs/*.md') ?: [];
        sort($docs, SORT_STRING);
        foreach ($docs as $absolute) {
            $sources[] = 'docs/' . basename($absolute);
        }

        $pages = [];
        foreach ((new Navigation())->groups($sources) as $group) {
            foreach ($group['sources'] as $source) {
                $pages[] = $this->pageFor($source, $group['label']);
            }
        }

        return $pages;
    }

    private function pageFor(string $source, string $group): Page
    {
        if ($source === 'README.md') {
            return new Page($source, 'index.html', '', 0, 'Overview', $group);
        }

        $name = basename($source, '.md');

        return new Page(
            $source,
            $name . '/index.html',
            $name . '/',
            1,
            $this->label($name),
            $group,
        );
    }

    /** `visual-builder` reads as "Visual builder" in a sidebar, not as a file name. */
    private function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    /**
     * @param list<Page> $pages
     */
    private function layout(Page $page, RenderedDocument $document, array $pages): string
    {
        $prefix = str_repeat('../', $page->depth);
        $title = $document->title ?? $page->label;
        $documentTitle = $page->depth === 0
            ? 'Click CMS documentation'
            : $this->escape($title) . ' — Click CMS';

        $navigation = $this->navigation(
            $pages,
            $page->source,
            static fn (Page $target): string => ($prefix . $target->url) === ''
                ? './'
                : $prefix . $target->url,
        );
        $contents = $this->tableOfContents($document);
        $sourceUrl = $this->repositoryUrl . '/blob/' . $this->branch . '/' . $page->source;

        return $this->page(
            $documentTitle,
            $prefix,
            $prefix === '' ? './' : $prefix,
            $navigation,
            $contents,
            $document->html,
            $sourceUrl,
        );
    }

    /**
     * The sidebar: one labelled list per navigation group, in the order
     * {@see Navigation} gives them.
     *
     * The group labels are paragraphs, not headings. The navigation is already
     * named for assistive technology by `aria-label`, and promoting a label to
     * `<h2>` would put a level-2 heading above the page's own `<h1>`, breaking
     * the document outline the accessibility audit checks. Each list is then
     * pointed back at its label with `aria-labelledby`, so a screen reader
     * announces "Running a site, list, 3 items" without a heading existing.
     *
     * @param list<Page> $pages
     * @param callable(Page): string $href Link to a page from wherever we are.
     */
    private function navigation(array $pages, ?string $currentSource, callable $href): string
    {
        $slugger = new Slugger();
        $html = "<nav class=\"pages\" aria-label=\"Documentation pages\">\n";
        $group = null;

        foreach ($pages as $page) {
            if ($page->group !== $group) {
                $html .= $group === null ? '' : "</ul>\n</div>\n";
                $group = $page->group;
                $id = 'nav-' . $slugger->slug($group);
                $html .= "<div class=\"nav-group\">\n"
                    . sprintf(
                        "<p class=\"nav-heading\" id=\"%s\">%s</p>\n<ul aria-labelledby=\"%s\">\n",
                        $this->escape($id),
                        $this->escape($group),
                        $this->escape($id),
                    );
            }

            // aria-current is the machine-readable half of "you are here"; the
            // class is the visible half. Neither alone is enough.
            $html .= sprintf(
                "<li><a%s href=\"%s\">%s</a></li>\n",
                $page->source === $currentSource ? ' class="current" aria-current="page"' : '',
                $this->escape($href($page)),
                $this->escape($page->label),
            );
        }

        $html .= $group === null ? '' : "</ul>\n</div>\n";

        return $html . '</nav>';
    }

    private function tableOfContents(RenderedDocument $document): string
    {
        $outline = $document->outline();
        if (count($outline) < 2) {
            return '';
        }

        $items = '';
        foreach ($outline as $heading) {
            $items .= sprintf(
                "<li class=\"level-%d\"><a href=\"#%s\">%s</a></li>\n",
                $heading['level'],
                $this->escape($heading['id']),
                $this->escape($heading['text']),
            );
        }

        return "<nav class=\"contents\" aria-label=\"On this page\">\n"
            . "<p class=\"nav-heading\">On this page</p>\n"
            . "<ul>\n" . $items . "</ul>\n</nav>";
    }

    private function page(
        string $documentTitle,
        string $assetBase,
        string $homeHref,
        string $navigation,
        string $contents,
        string $body,
        ?string $sourceUrl,
    ): string {
        $source = $sourceUrl !== null
            ? '<p><a href="' . $this->escape($sourceUrl) . '">Read this page as Markdown on GitHub</a></p>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$documentTitle}</title>
        <link rel="stylesheet" href="{$assetBase}style.css">
        </head>
        <body>
        <a class="skip-link" href="#content">Skip to content</a>
        <header class="masthead">
        <a class="wordmark" href="{$homeHref}">Click&nbsp;CMS</a>
        <p class="strapline">A flat-file CMS that requires PHP and nothing else</p>
        </header>
        <div class="shell">
        <div class="sidebar">
        {$navigation}
        {$contents}
        </div>
        <main id="content" tabindex="-1">
        <article class="prose">
        {$body}
        </article>
        </main>
        </div>
        <footer class="colophon">
        {$source}
        <p>Click CMS is MIT licensed. <a href="{$this->repositoryUrl}">Source on GitHub</a>.</p>
        </footer>
        </body>
        </html>

        HTML;
    }

    /**
     * @param list<Page> $pages
     */
    private function notFoundPage(array $pages): string
    {
        $base = '/' . trim($this->basePath, '/');
        $base = $base === '/' ? '/' : $base . '/';

        $navigation = $this->navigation(
            $pages,
            null,
            static fn (Page $page): string => $base . $page->url,
        );

        $body = "<h1>Not found</h1>\n"
            . "<p>There is no page at that address. It may have been renamed, or the link "
            . "that brought you here may predate a reorganisation of these docs.</p>\n"
            . "<p>Every page of the documentation is listed in the navigation.</p>";

        // A 404 is served for arbitrary paths, so relative links would resolve
        // against wherever the reader happened to be asking. These are absolute,
        // which is the one place the site needs to know its base path.
        return $this->page(
            'Not found — Click CMS',
            $this->escape($base),
            $this->escape($base),
            $navigation,
            '',
            $body,
            null,
        );
    }

    private function write(string $outputDirectory, string $relativePath, string $contents): string
    {
        $target = $outputDirectory . '/' . $relativePath;
        $this->makeDirectory(dirname($target));
        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException("Could not write {$target}.");
        }

        return $relativePath;
    }

    /** Copies a screenshot in, byte for byte, so the site needs nothing outside itself. */
    private function copy(string $outputDirectory, string $relativePath, string $sourcePath): string
    {
        $target = $outputDirectory . '/' . $relativePath;
        $this->makeDirectory(dirname($target));
        if (!copy($sourcePath, $target)) {
            throw new RuntimeException("Could not copy {$sourcePath} to {$target}.");
        }

        return $relativePath;
    }

    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException("Could not create directory {$path}.");
        }
    }

    private function stylesheet(): string
    {
        $path = __DIR__ . '/assets/site.css';
        $css = file_get_contents($path);
        if ($css === false) {
            throw new RuntimeException("Could not read the stylesheet at {$path}.");
        }

        return $css;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
