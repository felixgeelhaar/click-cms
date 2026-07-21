<?php

declare(strict_types=1);

namespace Click\Cms\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * What identifies a document: its type, its language, and its address.
 *
 * The locale is part of the key rather than a field inside the payload. That
 * makes `page/en/home` and `page/de/home` two ordinary documents, which in turn
 * makes a missing translation an absent document rather than a special state
 * every reader has to know how to interpret. The alternative — a map of
 * translations inside one document — makes every write to one language a write
 * to all of them, and every partial translation a shape the schema cannot
 * describe.
 *
 * The string form is `type:locale:slug`. `type:slug` is still accepted and
 * means the default locale, so keys written before languages existed still
 * parse and existing callers still compile.
 */
class ContentKey
{
    private function __construct(
        public readonly string $type,
        public readonly string $slug,
        public readonly Locale $locale
    ) {}

    public static function fromString(string $key): self
    {
        $parts = explode(':', $key);

        // Two parts is the pre-languages form and means the default locale.
        // Dropping it would orphan every key already written to disk.
        if (count($parts) === 2) {
            [$type, $slug] = $parts;
            $locale = Locale::default();
        } elseif (count($parts) === 3) {
            [$type, $localeCode, $slug] = $parts;
            $locale = Locale::tryFromString($localeCode);

            if ($locale === null) {
                throw new InvalidArgumentException(
                    "ContentKey locale \"{$localeCode}\" is not a valid language tag"
                );
            }
        } else {
            throw new InvalidArgumentException(
                'ContentKey must be in format "type:slug" or "type:locale:slug"'
            );
        }

        if (empty(trim($type)) || empty(trim($slug))) {
            throw new InvalidArgumentException('ContentKey type and slug cannot be empty');
        }

        return new self($type, $slug, $locale);
    }

    public static function page(string $slug, string|Locale|null $locale = null): self
    {
        return new self('page', $slug, self::locale($locale));
    }

    public static function user(string $slug, string|Locale|null $locale = null): self
    {
        return new self('user', $slug, self::locale($locale));
    }

    public static function media(string $slug, string|Locale|null $locale = null): self
    {
        return new self('media', $slug, self::locale($locale));
    }

    /** The same document in another language. */
    public function withLocale(string|Locale $locale): self
    {
        return new self($this->type, $this->slug, self::locale($locale));
    }

    public function toString(): string
    {
        return "{$this->type}:{$this->locale->code}:{$this->slug}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function locale(string|Locale|null $locale): Locale
    {
        if ($locale instanceof Locale) {
            return $locale;
        }

        return $locale === null ? Locale::default() : Locale::fromString($locale);
    }
}
