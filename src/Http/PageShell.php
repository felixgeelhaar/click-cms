<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * The full public-page document: `<!doctype>`, the language, the `<head>` (SEO
 * metadata or a preview title), the site header with its navigation, the theme
 * stylesheet, and the body wrapped in `<main>`.
 *
 * Extracted so that every way a page can be rendered produces the same
 * navigable, indexable chrome. The section renderer wraps its section markup in
 * it; the visual builder wraps its node tree in it — a builder page is no longer
 * a bare document with no nav, no SEO and no theme. Anything a caller has that is
 * body-specific (the builder's responsive `<style>` block, for instance) goes in
 * through `$extraHead`, so per-page head additions do not force a second shell.
 *
 * The pieces — language, head, header — are computed by the kernel (they read
 * storage, settings and media) and handed in here already rendered, so this
 * class does no I/O and stays trivially testable.
 */
final class PageShell
{
    /**
     * @param string $lang   The language actually served, already escaped, for
     *                        the `<html lang>` attribute.
     * @param string $head   The `<head>` inner tags — the SEO run for a public
     *                        page, or the title-plus-noindex for a preview.
     * @param string $header The site header markup (brand and main navigation),
     *                        or empty when the site has built neither.
     * @param string $stylesheetUrl The active theme's stylesheet, already
     *        cache-busted by the theme repository. It defaults to the historic
     *        `/theme.css` so an installation with no themes directory — and
     *        every existing caller, including the builder's shell — renders
     *        exactly as before.
     */
    public function __construct(
        private string $lang,
        private string $head,
        private string $header,
        private string $stylesheetUrl = '/theme.css',
        /**
         * The page's own name, rendered as the document's one `<h1>`.
         *
         * Every public page had none: the title lived only in `<title>`, so a
         * rendered page opened at heading level two with nothing above it. For
         * anyone navigating by headings that is a document with no name, and it
         * is the first thing an accessibility check reports.
         *
         * Optional and empty by default so a caller that has no title — the
         * not-found page — emits no empty heading rather than an anonymous one.
         */
        private string $title = '',
    ) {
    }

    /** The document's one `<h1>`, or nothing when the caller supplied no title. */
    private function pageHeading(): string
    {
        if (trim($this->title) === '') {
            return '';
        }

        return '<h1 class="cms-page-title">'
            . htmlspecialchars($this->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</h1>';
    }

    /**
     * The complete document, with $body inside `<main>`. $extraHead is appended
     * to the head verbatim — it is the caller's responsibility that it is safe.
     */
    public function render(string $body, string $extraHead = ''): string
    {
        $head = $this->head;
        if ($extraHead !== '') {
            $head .= "\n    " . $extraHead;
        }

        return '<!doctype html>
<html lang="' . $this->lang . '">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ' . $head . '
    <link rel="stylesheet" href="' . htmlspecialchars($this->stylesheetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">
</head>
<body>
    ' . $this->header . '<main>' . $this->pageHeading() . $body . '</main>
</body>
</html>';
    }
}
