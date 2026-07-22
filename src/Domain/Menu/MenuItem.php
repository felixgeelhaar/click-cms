<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Menu;

use Click\Cms\Domain\ValueObjects\Locale;
use InvalidArgumentException;

/**
 * One link in a navigation menu: a label and where it points.
 *
 * The target is validated here, at construction, and that is the whole point of
 * this class. A rendered nav is HTML the site emits with an editor-supplied
 * string sitting inside an `href` attribute. If that string were
 * `javascript:alert(document.cookie)` it would be a stored cross-site-scripting
 * payload — saved once by whoever can edit menus, then fired in the browser of
 * every visitor who clicks it. So a target is only ever one of two safe shapes:
 *
 *   - an internal page address — a slug (`^[a-z0-9][a-z0-9-]*$`), optionally
 *     prefixed with a locale (`de/about`), which the renderer turns into a
 *     same-origin path it controls; or
 *   - an absolute `http`/`https` URL, whose scheme is checked explicitly so that
 *     `javascript:`, `data:`, `mailto:`, `ftp:` and protocol-relative `//host`
 *     are all refused rather than passed through.
 *
 * Anything else throws. There is no third category and no "trust the editor"
 * escape hatch, because the editor's trust is exactly what an attacker who
 * reaches the editor would be borrowing.
 *
 * A menu item may carry children — one level only. Real navigation groups links
 * under a heading ("Products" → Widgets, Gadgets); a third level is refused so a
 * renderer that draws two cannot silently drop a deeper one.
 */
final class MenuItem
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * @param list<MenuItem> $children
     */
    private function __construct(
        private readonly string $label,
        private readonly string $target,
        private readonly bool $external,
        private readonly ?string $localeCode,
        private readonly ?string $slug,
        private readonly array $children,
    ) {}

    /**
     * @param list<MenuItem> $children
     */
    public static function create(string $label, string $target, array $children = []): self
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('A menu item needs a label.');
        }

        $target = trim($target);
        if ($target === '') {
            throw new InvalidArgumentException('A menu item needs a target.');
        }

        [$external, $localeCode, $slug] = self::classify($target);

        foreach ($children as $child) {
            if (!$child instanceof self) {
                throw new InvalidArgumentException('Menu children must be MenuItem instances.');
            }
            // One level only: a child that itself has children is a second level
            // of nesting the renderer does not draw.
            if ($child->children !== []) {
                throw new InvalidArgumentException(
                    'Menu nesting is limited to one level; a child item cannot have its own children.'
                );
            }
        }

        return new self($label, $target, $external, $localeCode, $slug, array_values($children));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $label = isset($data['label']) && is_string($data['label']) ? $data['label'] : '';
        $target = isset($data['target']) && is_string($data['target']) ? $data['target'] : '';

        $children = [];
        if (isset($data['children']) && is_array($data['children'])) {
            foreach ($data['children'] as $child) {
                if (is_array($child)) {
                    $children[] = self::fromArray($child);
                }
            }
        }

        return self::create($label, $target, $children);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    /** The locale a slug target was prefixed with, or null for a bare slug or a URL. */
    public function localeCode(): ?string
    {
        return $this->localeCode;
    }

    /** The internal page slug, or null when the target is an external URL. */
    public function slug(): ?string
    {
        return $this->slug;
    }

    /**
     * @return list<MenuItem>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'label' => $this->label,
            'target' => $this->target,
        ];

        // Only when there are children, so a flat menu stores no empty arrays and
        // a stored document reads as what it is.
        if ($this->children !== []) {
            $out['children'] = array_map(
                static fn (self $child): array => $child->toArray(),
                $this->children
            );
        }

        return $out;
    }

    /**
     * Decide what a target is, or reject it.
     *
     * @return array{0: bool, 1: ?string, 2: ?string} [external, localeCode, slug]
     */
    private static function classify(string $target): array
    {
        // A scheme followed by `://` is an absolute URL. Only http and https are
        // allowed through; every other scheme (and `javascript:` in particular,
        // which has no `//`) falls past this and is tested as an internal slug,
        // which it cannot be.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $target) === 1) {
            $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
            $host = parse_url($target, PHP_URL_HOST);

            if (($scheme === 'http' || $scheme === 'https') && is_string($host) && $host !== '') {
                return [true, null, null];
            }

            throw new InvalidArgumentException(
                "Menu target \"{$target}\" is not an allowed link. Use an http(s) URL or an internal page slug."
            );
        }

        // Internal: an optional locale segment, then a single slug segment.
        $parts = explode('/', $target);

        if (count($parts) === 1) {
            $slug = $parts[0];
            self::assertSlug($slug, $target);

            return [false, null, $slug];
        }

        if (count($parts) === 2) {
            [$localeCode, $slug] = $parts;

            if (Locale::tryFromString($localeCode) === null) {
                throw new InvalidArgumentException(
                    "Menu target \"{$target}\" has an invalid locale prefix \"{$localeCode}\"."
                );
            }
            self::assertSlug($slug, $target);

            return [false, Locale::fromString($localeCode)->code, $slug];
        }

        // Three or more segments: a page address here is a single slug, not a
        // path, so this cannot resolve to anything.
        throw new InvalidArgumentException(
            "Menu target \"{$target}\" is not a valid page slug or URL."
        );
    }

    private static function assertSlug(string $slug, string $target): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(
                "Menu target \"{$target}\" is neither a valid page slug nor an http(s) URL."
            );
        }
    }
}
