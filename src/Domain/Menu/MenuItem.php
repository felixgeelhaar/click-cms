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
 *     are all refused rather than passed through; or
 *   - a fragment — `#contact`, or `about#team` for a section of a named page.
 *     A one-page site's navigation is entirely anchors, which is the ordinary
 *     shape for the sites this CMS serves, and until this existed such a
 *     navigation could not be saved at all.
 *
 * Anything else throws. There is no "trust the editor" escape hatch, because
 * the editor's trust is exactly what an attacker who reaches the editor would
 * be borrowing. A fragment is held to the same standard as the rest: it must
 * look like an id, so nothing in it can close the attribute it will sit in or
 * smuggle a scheme.
 *
 * A menu item may carry children — one level only. Real navigation groups links
 * under a heading ("Products" → Widgets, Gadgets); a third level is refused so a
 * renderer that draws two cannot silently drop a deeper one.
 */
final class MenuItem
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * What may follow a `#`.
     *
     * An HTML id: a letter first, then letters, digits, hyphen, underscore,
     * colon or dot. Deliberately narrower than the spec — which allows almost
     * anything — because the value is written into an href attribute, and
     * quotes, spaces and angle brackets are exactly what an injection needs.
     */
    private const FRAGMENT_PATTERN = '/^[A-Za-z][A-Za-z0-9_:.-]*$/';

    /**
     * @param list<MenuItem> $children
     */
    private function __construct(
        private readonly string $label,
        private readonly string $target,
        private readonly bool $external,
        private readonly ?string $localeCode,
        private readonly ?string $slug,
        private readonly ?string $fragment,
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

        [$external, $localeCode, $slug, $fragment] = self::classify($target);

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

        return new self($label, $target, $external, $localeCode, $slug, $fragment, array_values($children));
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

    /**
     * The id this item points at, without the `#`, or null when it points at a
     * whole page.
     */
    public function fragment(): ?string
    {
        return $this->fragment;
    }

    /** Whether this points within a page rather than at one. */
    public function isAnchor(): bool
    {
        return $this->fragment !== null;
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
    /**
     * A fragment must look like an id and nothing else.
     *
     * It ends up inside an href attribute, so the characters that matter are
     * the ones that could leave it: quotes, spaces, angle brackets. Refusing
     * everything but an id shape is cheaper to reason about than escaping, and
     * an editor who wanted `#my section` meant `#my-section`.
     */
    private static function assertFragment(string $fragment, string $target): void
    {
        if (preg_match(self::FRAGMENT_PATTERN, $fragment) !== 1) {
            throw new InvalidArgumentException(
                "Menu target \"{$target}\" has an invalid anchor. Use an id such as #contact."
            );
        }
    }

    private static function classify(string $target): array
    {
        // A fragment is split off first, so `about#team` is judged as the page
        // `about` plus the id `team` rather than as a slug containing a `#`.
        // A bare `#contact` has no page part: it points within whatever page is
        // being viewed, which is what a one-page navigation means.
        $fragment = null;
        $hash = strpos($target, '#');
        if ($hash !== false) {
            $fragment = substr($target, $hash + 1);
            self::assertFragment($fragment, $target);
            $target = substr($target, 0, $hash);

            if ($target === '') {
                return [false, null, null, $fragment];
            }
        }

        // A scheme followed by `://` is an absolute URL. Only http and https are
        // allowed through; every other scheme (and `javascript:` in particular,
        // which has no `//`) falls past this and is tested as an internal slug,
        // which it cannot be.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $target) === 1) {
            $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
            $host = parse_url($target, PHP_URL_HOST);

            if (($scheme === 'http' || $scheme === 'https') && is_string($host) && $host !== '') {
                return [true, null, null, $fragment];
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

            return [false, null, $slug, $fragment];
        }

        if (count($parts) === 2) {
            [$localeCode, $slug] = $parts;

            if (Locale::tryFromString($localeCode) === null) {
                throw new InvalidArgumentException(
                    "Menu target \"{$target}\" has an invalid locale prefix \"{$localeCode}\"."
                );
            }
            self::assertSlug($slug, $target);

            return [false, Locale::fromString($localeCode)->code, $slug, $fragment];
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
