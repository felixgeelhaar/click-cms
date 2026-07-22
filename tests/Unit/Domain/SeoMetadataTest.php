<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Seo\SeoMetadata;
use PHPUnit\Framework\TestCase;

/**
 * The domain half of page SEO: normalising an editor's raw `seo` map into the
 * few decided values a renderer prints. No I/O, no escaping — escaping is the
 * renderer's job because it is a fact about the output format, not the model.
 */
final class SeoMetadataTest extends TestCase
{
    public function testMetaTitleWinsOverThePageTitle(): void
    {
        $meta = SeoMetadata::fromArray(['metaTitle' => 'Custom SEO title'], 'Page title');

        $this->assertSame('Custom SEO title', $meta->title);
    }

    public function testFallsBackToThePageTitleWhenMetaTitleIsEmpty(): void
    {
        $this->assertSame('Page title', SeoMetadata::fromArray(['metaTitle' => ''], 'Page title')->title);
        $this->assertSame('Page title', SeoMetadata::fromArray([], 'Page title')->title);
        // Whitespace is not a title; it falls back too.
        $this->assertSame('Page title', SeoMetadata::fromArray(['metaTitle' => '   '], 'Page title')->title);
    }

    public function testReadsTheOptionalFields(): void
    {
        $meta = SeoMetadata::fromArray([
            'metaTitle' => 'T',
            'description' => 'A short description',
            'ogImage' => 'photo-123',
            'canonicalUrl' => 'https://example.com/home',
            'noindex' => true,
        ], 'Page title');

        $this->assertSame('A short description', $meta->description);
        $this->assertSame('photo-123', $meta->ogImage);
        $this->assertSame('https://example.com/home', $meta->canonicalUrl);
        $this->assertTrue($meta->noindex);
    }

    public function testAbsentOptionalFieldsAreNull(): void
    {
        $meta = SeoMetadata::fromArray([], 'Page title');

        $this->assertNull($meta->description);
        $this->assertNull($meta->ogImage);
        $this->assertNull($meta->canonicalUrl);
        $this->assertFalse($meta->noindex);
    }

    public function testEmptyStringsAreTreatedAsAbsent(): void
    {
        $meta = SeoMetadata::fromArray([
            'description' => '',
            'ogImage' => '',
            'canonicalUrl' => '   ',
        ], 'Page title');

        $this->assertNull($meta->description);
        $this->assertNull($meta->ogImage);
        $this->assertNull($meta->canonicalUrl);
    }

    public function testNoindexAcceptsOnlyABooleanTrue(): void
    {
        // A stored JSON payload can carry anything; only a real boolean true
        // hides the page from crawlers, so a stray string cannot flip it.
        $this->assertFalse(SeoMetadata::fromArray(['noindex' => 'false'], 'T')->noindex);
        $this->assertFalse(SeoMetadata::fromArray(['noindex' => 0], 'T')->noindex);
        $this->assertTrue(SeoMetadata::fromArray(['noindex' => true], 'T')->noindex);
    }

    public function testNonStringScalarsDoNotBecomeText(): void
    {
        // Defensive: a corrupt store must not turn an array into a description.
        $meta = SeoMetadata::fromArray([
            'metaTitle' => ['not', 'a', 'string'],
            'description' => ['also not'],
        ], 'Page title');

        $this->assertSame('Page title', $meta->title);
        $this->assertNull($meta->description);
    }
}
