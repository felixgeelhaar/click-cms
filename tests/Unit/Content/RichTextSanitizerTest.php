<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Domain\Content\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Stored rich-text HTML is written straight into the public page as markup, so
 * it is an XSS surface: anything an editor — or anyone POSTing to the API — can
 * put in the field, a visitor's browser will run. Client-side sanitising is
 * bypassable, so this server-side allowlist is the real boundary. These tests
 * pin down exactly what survives it and what does not.
 */
final class RichTextSanitizerTest extends TestCase
{
    private RichTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new RichTextSanitizer();
    }

    public function testKeepsAllowedFormattingTags(): void
    {
        $html = '<p>Hello <strong>bold</strong> and <em>italic</em> and <b>b</b> and <i>i</i>.</p>';

        $this->assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testKeepsHeadingsListsAndBlockquote(): void
    {
        $out = $this->sanitizer->sanitize(
            '<h2>Title</h2><h3>Sub</h3><ul><li>one</li></ul><ol><li>two</li></ol><blockquote>quote</blockquote>'
        );

        $this->assertStringContainsString('<h2>Title</h2>', $out);
        $this->assertStringContainsString('<h3>Sub</h3>', $out);
        $this->assertStringContainsString('<ul><li>one</li></ul>', $out);
        $this->assertStringContainsString('<ol><li>two</li></ol>', $out);
        $this->assertStringContainsString('<blockquote>quote</blockquote>', $out);
    }

    public function testKeepsSafeHttpLink(): void
    {
        $out = $this->sanitizer->sanitize('<a href="https://example.com/x">link</a>');

        $this->assertStringContainsString('href="https://example.com/x"', $out);
        $this->assertStringContainsString('>link</a>', $out);
        // A public link the editor did not author gets untrusted-target hardening.
        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function testKeepsMailtoLink(): void
    {
        $out = $this->sanitizer->sanitize('<a href="mailto:hi@example.com">mail</a>');

        $this->assertStringContainsString('href="mailto:hi@example.com"', $out);
        $this->assertStringContainsString('>mail</a>', $out);
    }

    public function testRemovesScriptTagAndItsContents(): void
    {
        $out = $this->sanitizer->sanitize('<p>safe</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $out);
        // The script body must not survive as loose text either — a bare
        // alert(1) sitting in the page is at best confusing and at worst part of
        // a wider injection.
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<p>safe</p>', $out);
    }

    public function testRemovesEventHandlerAttributes(): void
    {
        $out = $this->sanitizer->sanitize('<p onclick="steal()">hi</p><a href="https://ok.test" onmouseover="x()">l</a>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringContainsString('<p>hi</p>', $out);
        $this->assertStringContainsString('href="https://ok.test"', $out);
    }

    public function testRemovesOnerrorOnDisallowedImage(): void
    {
        $out = $this->sanitizer->sanitize('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function testDropsJavascriptHref(): void
    {
        $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        // The wording is kept even though the destination was refused, so no
        // text silently vanishes.
        $this->assertStringContainsString('x', $out);
    }

    /**
     * A scheme can be hidden behind an HTML entity or a stray tab/newline —
     * `java&#09;script:` and `java\tscript:` both decode to a live URL in a
     * browser. The check must see through both.
     */
    public function testDropsObfuscatedJavascriptHref(): void
    {
        $entity = $this->sanitizer->sanitize('<a href="java&#09;script:alert(1)">a</a>');
        $control = $this->sanitizer->sanitize("<a href=\"java\tscript:alert(1)\">b</a>");

        $this->assertStringNotContainsString('javascript', strtolower($entity));
        $this->assertStringNotContainsString('alert', $entity);
        $this->assertStringNotContainsString('javascript', strtolower($control));
        $this->assertStringNotContainsString('alert', $control);
    }

    public function testDropsDataAndOtherSchemes(): void
    {
        $out = $this->sanitizer->sanitize('<a href="data:text/html,<script>alert(1)</script>">x</a>');

        $this->assertStringNotContainsString('data:', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testStripsStyleAttributesAndTags(): void
    {
        $out = $this->sanitizer->sanitize('<p style="position:fixed">a</p><style>body{display:none}</style>');

        $this->assertStringNotContainsString('style', $out);
        $this->assertStringNotContainsString('display:none', $out);
        $this->assertStringContainsString('<p>a</p>', $out);
    }

    /**
     * A disallowed wrapper is unwrapped rather than deleted: its safe children
     * and text stay. Deleting them would be the silent content loss this
     * codebase treats as a bug in its own right.
     */
    public function testUnwrapsDisallowedTagsButKeepsChildren(): void
    {
        $out = $this->sanitizer->sanitize('<div><span>keep <strong>this</strong></span></div>');

        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringNotContainsString('<span', $out);
        $this->assertStringContainsString('keep ', $out);
        $this->assertStringContainsString('<strong>this</strong>', $out);
    }

    public function testEscapesTextSpecialCharactersOnOutput(): void
    {
        // A rich-text editor emits literal angle brackets as entities, so this
        // is the realistic input. What must hold is that the decoded prose is
        // re-escaped on the way out: the "<" is data and must never come back as
        // the start of a tag.
        $out = $this->sanitizer->sanitize('<p>1 &lt; 2 &amp;&amp; 3 &gt; 2 &quot;q&quot;</p>');

        $this->assertStringContainsString('1 &lt; 2', $out);
        $this->assertStringContainsString('3 &gt; 2', $out);
        $this->assertStringContainsString('&amp;&amp;', $out);
        // Never a raw bracket that a browser could read as markup.
        $this->assertStringNotContainsString('< 2', $out);
        $this->assertStringNotContainsString('2 >', $out);
    }

    public function testMalformedNestingDoesNotBreakOutputOrEscape(): void
    {
        // Unclosed and mis-nested tags must not let anything escape the allowlist
        // or produce a fatal — the parser normalises, it does not trust.
        $out = $this->sanitizer->sanitize('<p><strong>bold <em>both</strong> italic</em><script>x()</script>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('x()', $out);
        $this->assertStringContainsString('bold ', $out);
        $this->assertStringContainsString('both', $out);
    }

    public function testEmptyAndWhitespaceInputYieldEmptyString(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize("   \n\t "));
    }

    public function testPreservesLineBreaks(): void
    {
        $out = $this->sanitizer->sanitize('<p>line one<br>line two</p>');

        $this->assertStringContainsString('<br>', $out);
        $this->assertStringContainsString('line one', $out);
        $this->assertStringContainsString('line two', $out);
    }

    /**
     * UTF-8 must survive intact; the classic DOMDocument mistake is to mangle
     * anything above ASCII into HTML entities or mojibake.
     */
    public function testPreservesUtf8Content(): void
    {
        $out = $this->sanitizer->sanitize('<p>Grüße — café — 日本語</p>');

        $this->assertStringContainsString('Grüße', $out);
        $this->assertStringContainsString('café', $out);
        $this->assertStringContainsString('日本語', $out);
    }
}
