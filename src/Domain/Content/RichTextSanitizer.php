<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Reduces untrusted rich-text HTML to a small allowlist of safe markup.
 *
 * A rich-text field is stored as HTML and written into the public page as
 * markup rather than escaped text, so whatever it contains, a visitor's browser
 * runs. That makes it a cross-site-scripting surface: a `<script>`, an
 * `onerror=` handler or a `javascript:` link left in the value would execute
 * against every reader of the page. The admin editor sanitises too, but that is
 * only a convenience — anyone can POST straight to the API and skip it — so this
 * server-side pass is the boundary that actually has to hold.
 *
 * The design is allowlist-only and parser-based on purpose:
 *
 * - **Allowlist, not blocklist.** Only the tags and the single attribute below
 *   pass through; everything else is refused. A blocklist of "dangerous" things
 *   is a guarantee that the next bypass has not been thought of yet.
 * - **A real parser, never regex.** HTML is not a regular language, and every
 *   regex "sanitiser" has been defeated by mis-nested or malformed input.
 *   `DOMDocument` (a core PHP extension, no Composer dependency) normalises the
 *   tree first, so the allowlist is applied to what a browser would actually
 *   build, not to the raw bytes.
 * - **Unwrap, don't delete.** A disallowed *wrapper* is removed but its safe
 *   children are kept, because silently discarding an editor's words is the
 *   quiet failure this codebase treats as a bug. The exception is elements whose
 *   content is itself code — `<script>` and `<style>` — which are dropped whole.
 *
 * Pure domain logic: it parses an in-memory string and returns one. It reads no
 * file, opens no socket and knows nothing about HTTP, so it stays inside the
 * domain's no-I/O rule.
 */
final class RichTextSanitizer
{
    /**
     * Tags a reader may safely be handed. Deliberately the vocabulary a basic
     * editor produces — emphasis, links, lists, headings and paragraphs — and
     * nothing that can load a resource, run code or restyle the page.
     *
     * @var array<string, true>
     */
    private const ALLOWED_TAGS = [
        'p' => true,
        'br' => true,
        'strong' => true,
        'em' => true,
        'b' => true,
        'i' => true,
        'a' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'h2' => true,
        'h3' => true,
        'blockquote' => true,
    ];

    /**
     * Elements with no children to keep: rendered as a single self-closed-style
     * tag. Only `<br>` qualifies in the allowlist.
     *
     * @var array<string, true>
     */
    private const VOID_TAGS = ['br' => true];

    /**
     * Tags whose text content is code, not prose. Unwrapping these would leak
     * the script or stylesheet body into the page as visible — and, in the case
     * of a `<style>`, still active — content, so they are dropped whole.
     *
     * @var array<string, true>
     */
    private const DROP_WHOLE = ['script' => true, 'style' => true];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Malformed rich text is the normal case, not the exception, so libxml's
        // complaints about it are expected and must not surface as warnings. The
        // previous error state is restored so this never leaks into a caller.
        $previous = libxml_use_internal_errors(true);

        // The XML encoding hint is the documented way to stop DOMDocument from
        // reinterpreting UTF-8 bytes as Latin-1, which would turn "Grüße" into
        // mojibake. LIBXML_NONET refuses any network fetch the parser might
        // otherwise be talked into.
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            // Nothing parseable; refusing beats emitting a guess.
            return '';
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMNode) {
            return '';
        }

        return $this->renderChildren($body);
    }

    private function renderChildren(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderNode($child);
        }

        return $out;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            // Text is data: its angle brackets and ampersands are escaped so a
            // "<" an editor typed cannot re-open as a tag once written back out.
            return $this->escape($node->textContent);
        }

        if (!$node instanceof DOMElement) {
            // Comments and processing instructions carry no prose worth keeping
            // and have been used to smuggle payloads past naive filters.
            return '';
        }

        $tag = strtolower($node->localName ?? $node->nodeName);

        if (isset(self::DROP_WHOLE[$tag])) {
            return '';
        }

        if (!isset(self::ALLOWED_TAGS[$tag])) {
            // A disallowed wrapper is unwrapped: keep the safe content within.
            return $this->renderChildren($node);
        }

        if (isset(self::VOID_TAGS[$tag])) {
            return '<' . $tag . '>';
        }

        if ($tag === 'a') {
            return $this->renderAnchor($node);
        }

        return '<' . $tag . '>' . $this->renderChildren($node) . '</' . $tag . '>';
    }

    /**
     * An anchor keeps its wording always, but its destination only when it is a
     * scheme that cannot execute. A refused link becomes plain text rather than
     * a dead `<a>` with no target.
     */
    private function renderAnchor(DOMElement $node): string
    {
        $inner = $this->renderChildren($node);
        $href = $this->safeHref($node->getAttribute('href'));

        if ($href === null) {
            return $inner;
        }

        // rel is fixed here rather than read from input: a link in editor
        // content points off-site to somewhere the editor does not control, so
        // it gets the same untrusted-target hardening the renderer already
        // applies to its own links.
        return '<a href="' . $this->escape($href) . '" rel="noopener noreferrer">' . $inner . '</a>';
    }

    /**
     * Returns the href if it uses a scheme safe to publish, otherwise null.
     *
     * Only http, https and mailto pass. javascript:, data: and vbscript: all run
     * or embed code; anything unrecognised is refused rather than guessed at.
     */
    private function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }

        // A scheme can be split by control characters or hidden behind an entity
        // — "java\tscript:" and "java&#09;script:" both run in a browser.
        // getAttribute has already decoded the entity; stripping the C0 control
        // range and spaces before comparing closes the tab/newline trick.
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $href) ?? $href);

        foreach (['http://', 'https://', 'mailto:'] as $scheme) {
            if (str_starts_with($probe, $scheme)) {
                return $href;
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
