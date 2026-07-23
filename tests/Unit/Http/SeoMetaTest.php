<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\SeoMeta;
use PHPUnit\Framework\TestCase;

/**
 * SeoMeta turns a page's data into the SEO tags that belong in <head>.
 *
 * The recurring risk this suite guards is that every value here is untrusted
 * editor input going straight into HTML attributes: a description of
 * `"><script>` must stay inside its attribute rather than open a script tag.
 * So most tests assert both that a tag is present and that a hostile value did
 * not break out of it.
 */
final class SeoMetaTest extends TestCase
{
    public function testRendersTheTitleFromTheMetaTitle(): void
    {
        $html = SeoMeta::forPage(['seo' => ['metaTitle' => 'SEO title']], 'Page title');

        $this->assertStringContainsString('<title>SEO title</title>', $html);
    }

    public function testTitleFallsBackToThePageTitle(): void
    {
        $html = SeoMeta::forPage(['seo' => ['metaTitle' => '']], 'Page title');

        $this->assertStringContainsString('<title>Page title</title>', $html);
    }

    public function testTitleFallsBackWhenThereIsNoSeoBlockAtAll(): void
    {
        $html = SeoMeta::forPage([], 'Page title');

        $this->assertStringContainsString('<title>Page title</title>', $html);
    }

    public function testRendersTheDescription(): void
    {
        $html = SeoMeta::forPage(['seo' => ['description' => 'A page about things']], 'Page title');

        $this->assertStringContainsString(
            '<meta name="description" content="A page about things">',
            $html
        );
    }

    public function testOmitsTheDescriptionWhenEmpty(): void
    {
        $html = SeoMeta::forPage(['seo' => ['description' => '']], 'Page title');

        $this->assertStringNotContainsString('name="description"', $html);
    }

    public function testAlwaysRendersOpenGraphTitleAndType(): void
    {
        $html = SeoMeta::forPage(['seo' => ['metaTitle' => 'SEO title']], 'Page title');

        $this->assertStringContainsString('<meta property="og:title" content="SEO title">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
    }

    public function testOpenGraphImageResolvesToAUrl(): void
    {
        $html = SeoMeta::forPage(
            ['seo' => ['ogImage' => 'photo-123']],
            'Page title',
            fn (string $ref): string => "https://cdn.example.com/media/{$ref}.jpg"
        );

        $this->assertStringContainsString(
            '<meta property="og:image" content="https://cdn.example.com/media/photo-123.jpg">',
            $html
        );
    }

    public function testOmitsTheOpenGraphImageWhenNoReferenceIsSet(): void
    {
        $html = SeoMeta::forPage(['seo' => []], 'Page title');

        $this->assertStringNotContainsString('og:image', $html);
    }

    public function testOmitsTheOpenGraphImageWhenTheResolverCannotResolveIt(): void
    {
        // A reference that no longer exists in the media library resolves to
        // nothing; an empty og:image tag is worse than none, so it is dropped.
        $html = SeoMeta::forPage(
            ['seo' => ['ogImage' => 'gone']],
            'Page title',
            fn (string $ref): string => ''
        );

        $this->assertStringNotContainsString('og:image', $html);
    }

    public function testRendersACanonicalLink(): void
    {
        $html = SeoMeta::forPage(
            ['seo' => ['canonicalUrl' => 'https://example.com/home']],
            'Page title'
        );

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://example.com/home">',
            $html
        );
    }

    public function testEmitsRobotsNoindexOnlyWhenSet(): void
    {
        $with = SeoMeta::forPage(['seo' => ['noindex' => true]], 'Page title');
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $with);

        $without = SeoMeta::forPage(['seo' => ['noindex' => false]], 'Page title');
        $this->assertStringNotContainsString('name="robots"', $without);

        $absent = SeoMeta::forPage(['seo' => []], 'Page title');
        $this->assertStringNotContainsString('name="robots"', $absent);
    }

    /* ------------------------------------------------------ escaping -- */

    public function testEscapesADescriptionThatTriesToBreakOutOfTheAttribute(): void
    {
        $html = SeoMeta::forPage(
            ['seo' => ['description' => '"><script>alert(1)</script>']],
            'Page title'
        );

        // The raw closing-quote-plus-tag must not survive verbatim, and no real
        // <script> element may appear in the output.
        $this->assertStringNotContainsString('"><script>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscapesAHostileTitle(): void
    {
        $html = SeoMeta::forPage(
            ['seo' => ['metaTitle' => '</title><script>alert(1)</script>']],
            'Page title'
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('</title><script>', $html);
    }

    public function testEscapesAHostileCanonicalUrl(): void
    {
        $html = SeoMeta::forPage(
            ['seo' => ['canonicalUrl' => 'https://x/"><script>alert(1)</script>']],
            'Page title'
        );

        $this->assertStringNotContainsString('"><script>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testEscapesAHostileResolvedImageUrl(): void
    {
        $html = SeoMeta::forPage(
            ['seo' => ['ogImage' => 'x']],
            'Page title',
            fn (string $ref): string => '"><script>alert(1)</script>'
        );

        $this->assertStringNotContainsString('"><script>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testASeoBlockThatIsNotAnArrayIsIgnored(): void
    {
        // A corrupt payload must degrade to the page title, not fatal.
        $html = SeoMeta::forPage(['seo' => 'nonsense'], 'Page title');

        $this->assertStringContainsString('<title>Page title</title>', $html);
    }
}
