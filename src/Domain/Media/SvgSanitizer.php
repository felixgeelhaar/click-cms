<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Media;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reduces an untrusted SVG to a small allowlist of safe drawing markup, or
 * refuses it.
 *
 * An SVG is not an image the way a JPEG is: it is an XML document, and a browser
 * that renders one inline executes it. A `<script>`, an `onload=` handler, a
 * `javascript:` href, a `<foreignObject>` carrying an `<iframe>`, or a `<use>`
 * that pulls in a remote fragment all run against every reader of the page the
 * SVG is shown on — a stored cross-site-scripting hole. That single fact is why
 * UploadPolicy refused SVG outright for so long: a raster upload cannot carry
 * code, and an SVG can. This class is the only thing that makes accepting one
 * defensible, so its output is what gets stored, never the raw upload.
 *
 * The design mirrors RichTextSanitizer, for the same reasons:
 *
 * - **Allowlist, not blocklist.** Only the elements and attributes named below
 *   survive; everything else is removed. A blocklist of "dangerous" things is a
 *   promise that the next bypass has not been imagined yet, and SVG has a long
 *   history of them (CDATA-wrapped script, namespaced handlers, entity tricks).
 * - **A real parser, never regex.** XML is not a regular language. `DOMDocument`
 *   (a core PHP extension, no Composer dependency) builds the tree, the allowlist
 *   is applied to that tree, and the result is re-serialised — so the output is
 *   only ever markup this class chose to emit, not bytes it tried to pattern out.
 * - **Remove whole, do not unwrap.** Where RichTextSanitizer keeps the children
 *   of a disallowed *prose* wrapper, this removes a disallowed element with its
 *   subtree. An SVG carries no words worth salvaging, and re-parenting the
 *   contents of an unrecognised element into a drawing context is precisely how
 *   a payload would try to smuggle itself past. Losing an unknown shape beats
 *   keeping an unknown one.
 * - **Refuse rather than half-clean.** Input that is not an SVG, will not parse,
 *   or has no `<svg>` root returns null. A half-sanitised SVG is the state the
 *   old blanket refusal existed to avoid, so this never emits a guess.
 *
 * Pure domain logic: it takes a string and returns one (or null). It reads no
 * file, opens no socket and knows nothing about HTTP, so it stays inside the
 * domain's no-I/O rule and is testable without fixtures.
 */
final class SvgSanitizer
{
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /**
     * Elements safe to keep: containers, shapes, gradients, clipping, text.
     * Compared lower-cased, so the camelCase XML names (`linearGradient`,
     * `clipPath`) are listed here folded. Anything absent — `script`, `style`,
     * `foreignObject`, `image`, `animate*`, `iframe`, `handler` — is removed
     * whole by virtue of not appearing.
     *
     * @var array<string, true>
     */
    private const ALLOWED_ELEMENTS = [
        'svg' => true,
        'g' => true,
        'defs' => true,
        'title' => true,
        'desc' => true,
        'symbol' => true,
        'use' => true,
        'a' => true,
        'path' => true,
        'rect' => true,
        'circle' => true,
        'ellipse' => true,
        'line' => true,
        'polyline' => true,
        'polygon' => true,
        'text' => true,
        'tspan' => true,
        'textpath' => true,
        'lineargradient' => true,
        'radialgradient' => true,
        'stop' => true,
        'clippath' => true,
        'mask' => true,
        'pattern' => true,
        'marker' => true,
    ];

    /**
     * Attributes safe to keep, compared lower-cased. Geometry, paint, gradient,
     * clipping, marker and text presentation — nothing that can load a resource
     * or run code. `href`/`xlink:href` and `style` are deliberately absent here
     * because they need value-level checks, not a flat yes; they are handled
     * separately below.
     *
     * @var array<string, true>
     */
    private const ALLOWED_ATTRIBUTES = [
        'id' => true, 'class' => true, 'transform' => true,
        'd' => true, 'points' => true,
        'x' => true, 'y' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true,
        'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true,
        'width' => true, 'height' => true, 'dx' => true, 'dy' => true, 'rotate' => true,
        'viewbox' => true, 'preserveaspectratio' => true, 'version' => true,
        'xmlns' => true, 'xml:space' => true,
        'fill' => true, 'fill-opacity' => true, 'fill-rule' => true,
        'stroke' => true, 'stroke-width' => true, 'stroke-opacity' => true,
        'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true,
        'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'opacity' => true, 'color' => true, 'visibility' => true, 'display' => true,
        'offset' => true, 'stop-color' => true, 'stop-opacity' => true,
        'gradientunits' => true, 'gradienttransform' => true, 'spreadmethod' => true,
        'fx' => true, 'fy' => true,
        'clip-path' => true, 'clip-rule' => true, 'mask' => true,
        'maskunits' => true, 'maskcontentunits' => true,
        'clippathunits' => true, 'patternunits' => true, 'patterncontentunits' => true,
        'patterntransform' => true,
        'marker-start' => true, 'marker-mid' => true, 'marker-end' => true,
        'markerwidth' => true, 'markerheight' => true, 'markerunits' => true,
        'refx' => true, 'refy' => true, 'orient' => true,
        'font-family' => true, 'font-size' => true, 'font-weight' => true,
        'font-style' => true, 'text-anchor' => true, 'dominant-baseline' => true,
        'letter-spacing' => true, 'word-spacing' => true,
    ];

    /**
     * Paint attributes whose value may legitimately be `url(#id)` — a reference
     * to a gradient, pattern, clip or marker defined in the same document. Any
     * `url()` that points anywhere else is stripped, because an off-document
     * paint reference is a resource load in disguise.
     *
     * @var array<string, true>
     */
    private const URL_BEARING = [
        'fill' => true, 'stroke' => true, 'clip-path' => true,
        'mask' => true, 'marker-start' => true, 'marker-mid' => true,
        'marker-end' => true,
    ];

    /**
     * Sanitise an SVG string, or return null when it cannot be made safe.
     *
     * Null is a refusal, not an empty drawing: the caller stores nothing and
     * tells the uploader the file was rejected, exactly as it would for a raster
     * that failed inspection.
     */
    public function sanitize(string $svg): ?string
    {
        if (!self::looksLikeSvg($svg)) {
            return null;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Malformed markup is expected from untrusted input, so libxml's
        // complaints must not surface as PHP warnings. The previous error state
        // is restored so nothing leaks into a caller.
        $previous = libxml_use_internal_errors(true);

        // The flags are the security boundary of the parse itself:
        // - Neither LIBXML_DTDLOAD nor LIBXML_NOENT is passed, so a DOCTYPE's
        //   entities are never loaded or expanded — that closes XXE and the
        //   billion-laughs expansion attack, both of which live in the DOCTYPE.
        // - LIBXML_NONET refuses any network fetch the parser could be talked
        //   into.
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return null;
        }

        // Drop any DOCTYPE outright. A drawing never needs one, and re-serialising
        // it would carry an entity-expansion vector back out.
        if ($dom->doctype instanceof DOMNode && $dom->doctype->parentNode !== null) {
            $dom->doctype->parentNode->removeChild($dom->doctype);
        }

        $root = $dom->documentElement;
        if (!$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return null;
        }

        $this->cleanElement($root);

        $result = $dom->saveXML($root);
        if ($result === false || trim($result) === '') {
            return null;
        }

        return $result;
    }

    /**
     * Whether the bytes are an SVG document, used to route an upload to this
     * sanitiser rather than to the raster processor (which `getimagesize` cannot
     * read an SVG with anyway).
     *
     * Strict on purpose: the document must *begin* with an SVG — after an
     * optional byte-order mark, XML declaration, comments and DOCTYPE — so a
     * binary raster that merely happens to contain the bytes "<svg" somewhere in
     * its payload is never misrouted here and then refused.
     */
    public static function looksLikeSvg(string $bytes): bool
    {
        // Drop a UTF-8 byte-order mark and leading whitespace before looking.
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
        $head = ltrim($head);

        return (bool) preg_match(
            '/^(<\?xml\b[^>]*\?>\s*)?'      // optional XML declaration
            . '(<!--.*?-->\s*)*'            // optional leading comments
            . '(<!DOCTYPE[^>]*>\s*)?'       // optional DOCTYPE
            . '(<!--.*?-->\s*)*'            // more optional comments
            . '<svg[\s>]/is',              // then the SVG root, and nothing before it
            $head
        );
    }

    /**
     * Strip a single element to the allowlist, then recurse.
     *
     * The element is assumed to be one that survives (its caller has checked);
     * here its attributes are pruned and its children are each either removed
     * whole or cleaned in turn.
     */
    private function cleanElement(DOMElement $element): void
    {
        $this->cleanAttributes($element);

        // Snapshot the children first: removing nodes mutates the live list, and
        // iterating it while it changes skips siblings.
        $children = [];
        foreach ($element->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (!isset(self::ALLOWED_ELEMENTS[strtolower($child->localName)])) {
                    // Not on the allowlist — removed with its whole subtree.
                    $element->removeChild($child);
                    continue;
                }

                $this->cleanElement($child);
                continue;
            }

            // Text and CDATA are data, kept as-is (re-serialisation escapes them).
            // Comments and processing instructions carry no drawing and have been
            // used to smuggle payloads past naive filters, so they are dropped.
            if (!($child instanceof \DOMText || $child instanceof \DOMCdataSection)) {
                $element->removeChild($child);
            }
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        // Snapshot for the same mutation-while-iterating reason as the children.
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            if (!$this->keepAttribute($element, $attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * Decide whether one attribute survives, and normalise the ones that do.
     */
    private function keepAttribute(DOMElement $element, DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName ?? $attribute->name);
        $qualified = strtolower($attribute->name);
        $value = $attribute->value;

        // Event handlers are the whole reason SVG is dangerous: onload, onclick,
        // onmouseover and the rest each run script. None is ever kept.
        if (str_starts_with($name, 'on')) {
            return false;
        }

        // Namespace declarations for xlink are needed for xlink:href to be valid
        // markup, so the SVG and xlink xmlns declarations are allowed through.
        if ($qualified === 'xmlns' || str_starts_with($qualified, 'xmlns:')) {
            return true;
        }

        // href / xlink:href: only a same-document (#id) reference is safe on any
        // element; an <a> may additionally link out over http(s) or mailto. A
        // javascript: or external target on a <use> or <image> is refused.
        if ($name === 'href') {
            return $this->isSafeReference($value, strtolower($element->localName) === 'a');
        }

        // The style attribute can carry url(javascript:…) and legacy expression()
        // payloads. Drawing uses presentation attributes (fill, stroke) instead,
        // so a style value is kept only when it is demonstrably inert.
        if ($name === 'style') {
            return $this->isSafeStyle($value);
        }

        if (!isset(self::ALLOWED_ATTRIBUTES[$name])) {
            return false;
        }

        // A paint reference must stay inside the document; an external url() is a
        // resource load wearing a fill's clothes.
        if (isset(self::URL_BEARING[$name]) && !$this->isSafePaint($value)) {
            return false;
        }

        return true;
    }

    /**
     * A reference is safe when it is same-document (#id). For an anchor only,
     * an ordinary web scheme is also allowed, since a link that navigates is the
     * point of an <a>. Everything else — javascript:, data:, an off-document
     * fragment — is refused.
     */
    private function isSafeReference(string $value, bool $isAnchor): bool
    {
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $value) ?? $value);

        if (str_starts_with($probe, '#')) {
            return true;
        }

        if ($isAnchor) {
            foreach (['http://', 'https://', 'mailto:'] as $scheme) {
                if (str_starts_with($probe, $scheme)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isSafePaint(string $value): bool
    {
        if (!preg_match('/url\s*\(/i', $value)) {
            return true;
        }

        // The only url() a paint may use is an internal reference: url(#id).
        return (bool) preg_match('/url\s*\(\s*[\'"]?#/i', $value)
            && !preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $value);
    }

    private function isSafeStyle(string $value): bool
    {
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $value) ?? $value);

        foreach (['javascript:', 'expression(', '@import', 'behavior:', '-moz-binding', '<'] as $bad) {
            if (str_contains($probe, $bad)) {
                return false;
            }
        }

        // Any url() in a style is only safe pointing at a same-document fragment.
        if (str_contains($probe, 'url(') && !preg_match('/url\(\s*[\'"]?#/', $probe)) {
            return false;
        }

        return true;
    }
}
