<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Media;

use Click\Cms\Domain\Media\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The SVG sanitiser is the single reason SVG can be accepted at all.
 *
 * An SVG is an XML document a browser executes when it is served inline: a
 * `<script>`, an `onload=` handler or a `javascript:` href in one runs against
 * every reader of the page it is shown on. That is a stored cross-site-scripting
 * hole, and it is exactly why UploadPolicy refused SVG outright until now. These
 * tests pin the boundary that replaced that blanket refusal: dangerous markup is
 * stripped or the whole file is refused, and only safe drawing survives.
 */
final class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SvgSanitizer();
    }

    public function testStripsAScriptElement(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<script>alert(document.cookie)</script>'
            . '<rect x="0" y="0" width="10" height="10" fill="#f00"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        // The drawing survives; only the code is removed.
        $this->assertStringContainsString('<rect', $clean);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<rect width="10" height="10" onload="alert(1)" onclick="steal()"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('onload', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        // The geometry stays.
        $this->assertStringContainsString('width="10"', $clean);
    }

    public function testStripsAnOnloadOnTheRootSvgElement(): void
    {
        // The classic payload: the handler rides on <svg> itself, not a child.
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="5" height="5"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('onload', $clean);
    }

    public function testRemovesForeignObject(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<foreignObject><iframe src="javascript:alert(1)"></iframe></foreignObject>'
            . '<circle cx="5" cy="5" r="4"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('foreignObject', $clean);
        $this->assertStringNotContainsStringIgnoringCase('iframe', $clean);
        $this->assertStringContainsString('<circle', $clean);
    }

    public function testStripsAJavascriptHrefOnAnAnchor(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<a xlink:href="javascript:alert(1)"><text x="0" y="10">click</text></a></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        // The wording is kept even when its dangerous destination is dropped.
        $this->assertStringContainsString('click', $clean);
    }

    public function testStripsAnExternalUseReference(): void
    {
        // A <use> that points off-document can pull in a remote fragment that
        // itself carries script; only same-document (#id) references are safe.
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<use xlink:href="https://evil.example/x.svg#p"/>'
            . '<use xlink:href="#safe"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('evil.example', $clean);
        $this->assertStringNotContainsStringIgnoringCase('https://', $clean);
        // The internal reference is legitimate and survives.
        $this->assertStringContainsString('#safe', $clean);
    }

    public function testRemovesAnEmbeddedStyleElement(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<style>* { background: url(javascript:alert(1)); }</style>'
            . '<rect width="10" height="10"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('<style', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function testRemovesAnExternalImageReference(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<image xlink:href="https://evil.example/track.png" width="1" height="1"/>'
            . '<rect width="10" height="10"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('<image', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);
    }

    public function testCleanSvgRoundTripsWithItsDrawingIntact(): void
    {
        $clean = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><linearGradient id="g"><stop offset="0%" stop-color="#fff"/>'
            . '<stop offset="100%" stop-color="#000"/></linearGradient></defs>'
            . '<path d="M10 10 H 90 V 90 H 10 Z" fill="url(#g)" stroke="#333" stroke-width="2"/>'
            . '<circle cx="50" cy="50" r="20" fill="#0a0"/></svg>';

        $result = $this->sanitizer->sanitize($clean);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<path', $result);
        $this->assertStringContainsString('d="M10 10 H 90 V 90 H 10 Z"', $result);
        $this->assertStringContainsString('<circle', $result);
        $this->assertStringContainsString('<linearGradient', $result);
        // An internal paint reference is legitimate and must be kept.
        $this->assertStringContainsString('url(#g)', $result);
    }

    public function testRefusesInputThatIsNotAnSvg(): void
    {
        $this->assertNull($this->sanitizer->sanitize('<html><body>not svg</body></html>'));
        $this->assertNull($this->sanitizer->sanitize('just some text'));
        $this->assertNull($this->sanitizer->sanitize(''));
    }

    public function testRefusesMalformedXml(): void
    {
        // Unusable input is refused rather than guessed at — a half-parsed SVG
        // is exactly the "half-sanitised" state the old blanket refusal avoided.
        $this->assertNull($this->sanitizer->sanitize('<svg><rect'));
    }

    public function testDropsADoctypeSoEntityAttacksCannotSurvive(): void
    {
        // A DOCTYPE is where XML entity-expansion and external-entity (XXE)
        // attacks live. A drawing never needs one, so the serialised output must
        // carry none regardless of what the upload declared.
        $dirty = '<?xml version="1.0"?>'
            . '<!DOCTYPE svg [<!ENTITY x "expanded">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';

        $clean = $this->sanitizer->sanitize($dirty);

        // Either refused outright or serialised without the doctype; never a
        // document that still declares one.
        if ($clean !== null) {
            $this->assertStringNotContainsStringIgnoringCase('<!DOCTYPE', $clean);
            $this->assertStringNotContainsStringIgnoringCase('<!ENTITY', $clean);
        } else {
            $this->assertNull($clean);
        }
    }

    public function testLooksLikeSvgRoutesOnlyRealSvgBytes(): void
    {
        $this->assertTrue(SvgSanitizer::looksLikeSvg('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        $this->assertTrue(SvgSanitizer::looksLikeSvg("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<svg></svg>"));
        $this->assertTrue(SvgSanitizer::looksLikeSvg("  \n<!-- a comment -->\n<svg></svg>"));

        // Binary rasters and unrelated text must not be routed to the SVG path.
        $this->assertFalse(SvgSanitizer::looksLikeSvg("\xFF\xD8\xFF\xE0JFIF")); // JPEG magic
        $this->assertFalse(SvgSanitizer::looksLikeSvg("\x89PNG\r\n")); // PNG magic
        $this->assertFalse(SvgSanitizer::looksLikeSvg('<html><svg></svg></html>'));
        $this->assertFalse(SvgSanitizer::looksLikeSvg('plain text'));
    }
}
