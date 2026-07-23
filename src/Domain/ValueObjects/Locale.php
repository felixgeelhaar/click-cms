<?php

declare(strict_types=1);

namespace Click\Cms\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A language tag: `en`, `de`, `pt-BR`.
 *
 * A value object rather than a bare string because a locale becomes a path
 * segment in flat-file storage and an attribute value in rendered HTML, so the
 * places it can do damage are exactly the places nobody remembers to validate.
 * Validating once, here, means `JsonStorage` cannot be handed `../..` dressed
 * up as a language and `SectionRenderer` cannot be handed a quote.
 *
 * The accepted shape is a deliberate subset of BCP 47: a two or three letter
 * language, optionally followed by dash-separated alphanumeric subtags. That
 * covers every tag a CMS realistically stores and excludes the extension and
 * private-use syntax that nothing here would know what to do with.
 *
 * Tags are normalised on the way in — `EN` and `pt-br` are the same locale as
 * `en` and `pt-BR` — because otherwise the same language spelt two ways becomes
 * two documents, and the second one is invisible to whoever wrote the first.
 */
final class Locale
{
    /**
     * The locale assumed when nothing says otherwise.
     *
     * The domain cannot read configuration, so this is the fallback of last
     * resort. A site that has configured `core.languages.default` passes that
     * value in explicitly; this constant is what keeps `ContentKey::page('home')`
     * meaningful for the callers — tests, mostly — that have no configuration
     * to hand.
     */
    public const DEFAULT = 'en';

    private const PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/';

    private function __construct(public readonly string $code) {}

    public static function fromString(string $code): self
    {
        $locale = self::tryFromString($code);

        if ($locale === null) {
            throw new InvalidArgumentException(
                "\"{$code}\" is not a valid language tag."
            );
        }

        return $locale;
    }

    /**
     * Parse, or null.
     *
     * Used wherever the tag came from a request. A locale nobody can parse is a
     * miss to be handled, not a 500 to be served.
     */
    public static function tryFromString(?string $code): ?self
    {
        if ($code === null) {
            return null;
        }

        $code = trim($code);

        if (preg_match(self::PATTERN, $code) !== 1) {
            return null;
        }

        return new self(self::normalise($code));
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function toString(): string
    {
        return $this->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    /**
     * Language lowercase, two-letter region uppercase, everything else
     * lowercase — the conventional spelling, so `pt-br` and `PT-BR` cannot
     * become two separate documents.
     */
    private static function normalise(string $code): string
    {
        $parts = explode('-', $code);
        $parts[0] = strtolower($parts[0]);

        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $parts[$i] = strlen($parts[$i]) === 2
                ? strtoupper($parts[$i])
                : strtolower($parts[$i]);
        }

        return implode('-', $parts);
    }
}
