<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Content;

use Click\Cms\Domain\ValueObjects\Locale;

/**
 * A document, together with the language that was asked for and the language
 * that was actually found.
 *
 * Falling back to the default locale is the right behaviour — a visitor asking
 * for German should see the English page rather than a 404 — but doing it
 * silently is the failure mode this codebase keeps having to fix. A front end
 * that receives German-looking JSON containing English prose has no way to know
 * a translation is missing: it cannot set `lang` correctly, cannot show a
 * "not yet translated" notice, and cannot report the gap to an editor.
 *
 * So the fallback is carried in the result rather than hidden in it. Anything
 * that serves content answers with the locale it served.
 */
final class ResolvedContent
{
    public function __construct(
        public readonly Content $content,
        public readonly Locale $requested,
        public readonly Locale $served,
    ) {}

    /** True when the requested language had no document and another was served. */
    public function isFallback(): bool
    {
        return !$this->requested->equals($this->served);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requestedLocale' => $this->requested->code,
            'locale' => $this->served->code,
            'fallback' => $this->isFallback(),
        ];
    }
}
