<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Domain\ValueObjects\Locale;

/**
 * A read-only delivery API that searches published pages.
 *
 * Search is an editorial feature, not core (see docs/core.md): a self-rendering
 * site can do without it, so it lives here as a plugin. What it must never do is
 * become the leak the GraphQL plugin already had to be rewritten to close.
 *
 * That plugin once answered `{ user(username:"admin") { password } }` and could
 * write unvalidated content; the fix was to read published pages and nothing
 * else. A search endpoint has the identical failure mode wearing different
 * clothes: the thing it is *for* is trawling text and handing back matches, so
 * the moment it reads a working copy it publishes an editor's unannounced draft
 * to anyone who guesses the right word — a leak, exactly like the account bug,
 * just discovered by a visitor's search box instead of a crafted query.
 *
 * So this reads only through {@see ContentService::pages()}, whose source is
 * `content/` — the published documents, the same bytes the public site renders.
 * A draft lives in the version chain and is never in that list, which is what
 * makes it safe to answer this anonymously (see the note in the report about the
 * one line the kernel's public allowlist still needs). It searches pages only,
 * never user or media documents, and it never writes.
 */
class Plugin_search_api extends \Click\Cms\Application\Plugin\BasePlugin
{
    /** Characters of context to show on either side of the first match. */
    private const EXCERPT_RADIUS = 60;

    public function getPluginId(): string
    {
        return 'search';
    }

    public function getPluginName(): string
    {
        return 'Search API';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, callable>
     */
    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/search' => [$this, 'handleSearch'],
        ];
    }

    /**
     * Search published pages for `?q=`, optionally scoped to `?locale=`.
     *
     * @return array{query: string, locale: string, results: list<array{slug: string, title: string, excerpt: string, locale: string}>}
     */
    public function handleSearch(): array
    {
        $query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');

        // A locale that came off the request is parsed leniently: a tag nobody
        // can read is a miss to fold back onto the default, not a 500 to serve.
        $localeParam = trim(is_string($_GET['locale'] ?? null) ? $_GET['locale'] : '');
        $locale = $localeParam === '' ? null : Locale::tryFromString($localeParam);

        $contentService = $this->pluginManager->getContentService();
        $effectiveLocale = $locale ?? $contentService->defaultLocale();

        // An empty or missing query returns nothing, not everything. "Show me
        // every page" is not a search, and a delivery endpoint that dumped the
        // whole site for a blank box would be a scraper's convenience.
        if ($query === '') {
            return ['query' => '', 'locale' => $effectiveLocale->code, 'results' => []];
        }

        // pages() reads `content/` — published documents only. This is the line
        // the whole plugin's safety rests on: never workingCopies(), never
        // draftPages(), or an unpublished draft would surface here.
        $pages = $contentService->pages($locale);

        $ranked = [];
        foreach ($pages as $page) {
            $title = $page->title();
            $body = $this->plainText($this->collectText($page->data['sections'] ?? null));

            $titleHit = mb_stripos($title, $query) !== false;
            $bodyHit = mb_stripos($body, $query) !== false;

            if (!$titleHit && !$bodyHit) {
                continue;
            }

            // The excerpt is drawn from wherever the match is, preferring the
            // body: a title hit already returns the title verbatim, so body
            // context is the more useful thing to show alongside it.
            $source = $bodyHit ? $body : $title;

            $ranked[] = [
                // A title hit outranks a body-only hit; nothing fancier. Lower
                // sorts first, and the loop preserves page order within a rank.
                'rank' => $titleHit ? 0 : 1,
                'result' => [
                    'slug' => $page->slug(),
                    'title' => $title,
                    'excerpt' => $this->excerpt($source, $query),
                    'locale' => $page->locale()->code,
                ],
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return [
            'query' => $query,
            'locale' => $effectiveLocale->code,
            'results' => array_map(static fn (array $r): array => $r['result'], $ranked),
        ];
    }

    /**
     * Every string buried in a page's sections, in reading order.
     *
     * Sections nest — a section has `values`, a repeater field is a list of rows,
     * a row has its own fields — so the text an editor typed lives at several
     * depths. Walking the structure recursively collects headings, prose, rich
     * text and repeater rows alike without the plugin needing to know any
     * particular section type, which is the point of core owning structure while
     * a site owns its section shapes.
     *
     * @return list<string>
     */
    private function collectText(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            foreach ($this->collectText($item) as $text) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Reduce collected fragments to one plain-text haystack.
     *
     * Rich-text fields are HTML by design, so their tags are stripped before a
     * byte is searched or excerpted — otherwise a match on the word "strong"
     * could land inside a `<strong>` tag and the returned excerpt would carry
     * raw markup into whatever renders it, an injection surface handed straight
     * to the caller. Entities are decoded so `&amp;` reads as `&`, and runs of
     * whitespace collapse so an excerpt is not padded with the newlines that
     * separated two fields.
     *
     * @param list<string> $fragments
     */
    private function plainText(array $fragments): string
    {
        $joined = implode("\n", $fragments);
        $stripped = strip_tags($joined);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }

    /**
     * A short window of `$text` centred on the first case-insensitive match, so
     * the caller can show why a page matched. The text handed in is already
     * plain — {@see plainText()} stripped any markup — so nothing here can emit
     * a tag; an ellipsis marks each end that was trimmed off.
     */
    private function excerpt(string $text, string $query): string
    {
        $pos = mb_stripos($text, $query);
        if ($pos === false) {
            // The match was in the title while the excerpt source is the body,
            // or vice versa: fall back to the head of the text rather than fail.
            $pos = 0;
        }

        $start = max(0, $pos - self::EXCERPT_RADIUS);
        $length = mb_strlen($query) + self::EXCERPT_RADIUS * 2;

        $window = mb_substr($text, $start, $length);

        if ($start > 0) {
            $window = '…' . ltrim($window);
        }
        if ($start + $length < mb_strlen($text)) {
            $window = rtrim($window) . '…';
        }

        return $window;
    }
}
