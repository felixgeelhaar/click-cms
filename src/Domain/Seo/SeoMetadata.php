<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Seo;

/**
 * The page-level SEO metadata a site needs and search engines expect: the meta
 * title, description, an Open Graph image reference, a canonical URL and a
 * "hide from crawlers" switch.
 *
 * A value object, not a renderer. It performs no I/O and does no escaping — it
 * only decides the effective values from an editor's raw `seo` map, because
 * those decisions (the title falls back to the page's own when empty, an empty
 * string is the same as an absent field) are facts about the model. How those
 * values are made safe for HTML is a fact about the output, and lives with the
 * renderer in {@see \Click\Cms\Http\SeoMeta}. Keeping the two apart is what lets
 * this be tested without a single expectation about angle brackets.
 *
 * The Open Graph image is stored as a media *reference*, exactly like a
 * section's image field, and is resolved to a URL at render time. Resolving it
 * here would mean reading the media library, and the domain reads nothing.
 */
final class SeoMetadata
{
    private function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $ogImage,
        public readonly ?string $canonicalUrl,
        public readonly bool $noindex,
    ) {}

    /**
     * Build from a page's `seo` map, falling back to the page's own title when
     * no meta title is set.
     *
     * @param array<string, mixed> $seo           The `seo` sub-array of a page's data.
     * @param string               $fallbackTitle The page's own title, used when
     *                                             `metaTitle` is empty — a page
     *                                             with a title but no SEO title
     *                                             still gets a sensible <title>.
     */
    public static function fromArray(array $seo, string $fallbackTitle): self
    {
        $metaTitle = self::text($seo['metaTitle'] ?? null);

        return new self(
            title: $metaTitle ?? $fallbackTitle,
            description: self::text($seo['description'] ?? null),
            ogImage: self::text($seo['ogImage'] ?? null),
            canonicalUrl: self::text($seo['canonicalUrl'] ?? null),
            // Only a real boolean true hides the page. A stored payload can carry
            // the string "false", which is truthy; treating that as "hide" would
            // silently drop a page from every search engine.
            noindex: ($seo['noindex'] ?? null) === true,
        );
    }

    /**
     * A trimmed, non-empty string, or null.
     *
     * Anything that is not a string — an array from a corrupt store, a number —
     * is treated as absent rather than coerced, so a broken payload degrades to
     * a missing field instead of printing "Array".
     */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
