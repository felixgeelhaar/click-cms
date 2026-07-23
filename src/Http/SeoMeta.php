<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Seo\SeoMetadata;

/**
 * Renders a page's SEO metadata as the <head> tags every site needs: the
 * <title>, the meta description, the Open Graph title/type/image, a canonical
 * link and a robots noindex when the editor has hidden the page.
 *
 * Every value printed here is untrusted editor input going straight into an
 * HTML attribute. A description of `"><script>` would otherwise close the
 * attribute and open a script tag in the document head; the whole reason this
 * class exists as a single escaping choke point is that one field escaped
 * wrongly is a stored XSS on every visitor. So there is exactly one way values
 * leave this class — {@see attr()} — and nothing is concatenated raw.
 *
 * It is pure: no I/O and no state. The Open Graph image is a media reference,
 * and turning a reference into a URL means reading the media library, which is
 * I/O this class must not do. The caller passes a resolver closure instead —
 * the same {@see \Click\Cms\Application\Media\MediaService} the section
 * renderer uses, wrapped by the integrator — so image resolution stays where
 * the I/O already lives and this class stays testable without fixtures.
 */
final class SeoMeta
{
    /**
     * The SEO <head> tags for a page, ready to drop into the document head.
     *
     * Returned as a run of tags, each on its own line indented four spaces to
     * sit alongside the other head tags, with no trailing newline. An empty
     * `seo` map still yields a <title> and the Open Graph title/type, because
     * those always have a value to print.
     *
     * @param array<string, mixed> $pageData        The page's `data` array; its
     *                                               `seo` sub-array is read here.
     * @param string               $fallbackTitle   The page's own title, used
     *                                               when no meta title is set.
     * @param null|callable(string): string $resolveImageUrl
     *        Maps the Open Graph media reference to a URL. Returns an empty
     *        string when the reference cannot be resolved (e.g. a deleted item),
     *        in which case the og:image tag is dropped rather than left empty.
     *        When null, the reference is used verbatim.
     */
    public static function forPage(
        array $pageData,
        string $fallbackTitle = '',
        ?callable $resolveImageUrl = null,
    ): string {
        $raw = $pageData['seo'] ?? null;
        $meta = SeoMetadata::fromArray(is_array($raw) ? $raw : [], $fallbackTitle);

        $tags = [];

        $tags[] = '<title>' . self::attr($meta->title) . '</title>';

        if ($meta->description !== null) {
            $tags[] = '<meta name="description" content="' . self::attr($meta->description) . '">';
        }

        // og:title and og:type always have a value — the title falls back to the
        // page's own, and the type of a CMS page is always a website.
        $tags[] = '<meta property="og:title" content="' . self::attr($meta->title) . '">';
        $tags[] = '<meta property="og:type" content="website">';

        if ($meta->ogImage !== null) {
            $url = $resolveImageUrl !== null ? (string) $resolveImageUrl($meta->ogImage) : $meta->ogImage;
            // An empty URL means the reference did not resolve; an empty
            // og:image is a broken share card, so it is omitted entirely.
            if ($url !== '') {
                $tags[] = '<meta property="og:image" content="' . self::attr($url) . '">';
            }
        }

        if ($meta->canonicalUrl !== null) {
            $tags[] = '<link rel="canonical" href="' . self::attr($meta->canonicalUrl) . '">';
        }

        if ($meta->noindex) {
            $tags[] = '<meta name="robots" content="noindex">';
        }

        return "    " . implode("\n    ", $tags);
    }

    /**
     * The single exit for every value printed by this class.
     *
     * ENT_QUOTES escapes both quote styles so a value cannot end an attribute,
     * and ENT_SUBSTITUTE replaces malformed UTF-8 rather than returning an empty
     * string — an editor paste from Word is exactly the input that carries it,
     * and a silently blanked description is the failure this project forbids.
     */
    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
