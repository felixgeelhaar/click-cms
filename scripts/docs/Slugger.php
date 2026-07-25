<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * Turns heading text into a stable URL fragment, and remembers what it has
 * already handed out.
 *
 * Two things make this more than a `strtolower()`. First, these headings carry
 * punctuation that must not survive into a URL — `` `Core\Application` ``,
 * `Route 1 — Docker`, `NoSQL / document stores`. Second, a long document
 * genuinely repeats headings ("What holds", "Where things live"), and two
 * elements sharing an id is invalid HTML that silently breaks every anchor to
 * the second one. Collisions are therefore numbered rather than ignored.
 *
 * One instance serves one document, so ids are unique per page and a rebuild of
 * unchanged input produces the same ids in the same order — the determinism the
 * build depends on.
 */
final class Slugger
{
    /** @var array<string, int> */
    private array $seen = [];

    public function slug(string $text): string
    {
        $slug = $this->normalise($text);

        if ($slug === '') {
            $slug = 'section';
        }

        if (!isset($this->seen[$slug])) {
            $this->seen[$slug] = 1;

            return $slug;
        }

        $this->seen[$slug]++;

        return $slug . '-' . $this->seen[$slug];
    }

    /** Normalises without recording, for callers that only want the shape. */
    public function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        // Keep letters, numbers, spaces and hyphens; everything else is dropped
        // rather than transliterated, which is what GitHub's anchors do too.
        $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $text) ?? $text;
        $text = preg_replace('/[\s_]+/u', '-', trim($text)) ?? $text;
        $text = preg_replace('/-{2,}/', '-', $text) ?? $text;

        return trim($text, '-');
    }

    /**
     * A conservative token for a CSS class or similar attribute value, used for
     * the `language-*` class on code fences: an info string is arbitrary text
     * from the document and has no business reaching an attribute unfiltered.
     */
    public function safeToken(string $text): string
    {
        $token = preg_replace('/[^A-Za-z0-9_+-]+/', '', $text) ?? '';

        return $token === '' ? 'text' : $token;
    }
}
