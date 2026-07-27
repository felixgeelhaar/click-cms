<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Menu\Menu;
use Click\Cms\Domain\Menu\MenuItem;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use InvalidArgumentException;

/**
 * Building the navigation menus an editor assembles and the site renders.
 *
 * Menus are ordinary content documents of type `menu`, so they go through the
 * same {@see ContentService} as everything else and inherit its storage,
 * backups and history rather than getting a parallel store of their own.
 *
 * These are management endpoints: an editor builds a menu, a visitor never
 * calls these. The kernel's {@see ApiGuard} is deny-by-default, so a menu route
 * is protected by simply not being on any public allowlist — mirroring how user
 * and plugin management are protected. The one thing the public path needs is a
 * *read* of a finished menu, and that does not come through these routes: it
 * comes through {@see resolvedItems()}, which the integrator calls from the
 * public renderer.
 *
 * The security-critical property is delegated to the domain. Every item's
 * target is validated by {@see MenuItem} before a menu can be constructed, so a
 * `javascript:` link is refused here with a 400 and never reaches storage — a
 * rendered nav is HTML with an editor's string inside an `href`, and an
 * unvalidated target there is stored XSS fired in every visitor's browser.
 */
final class MenusController
{
    public function __construct(private readonly ContentService $content) {}

    /**
     * @return array<string, array{string, callable}>
     */
    public function routes(): array
    {
        return [
            'GET /api/menus' => [$this, 'list'],
            'GET /api/menus/:id' => [$this, 'get'],
            'PUT /api/menus/:id' => [$this, 'put'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return [
            'data' => array_map(
                fn (Content $c): array => $this->menuOf($c)->toArray(),
                $this->content->all('menu')
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $locale = $this->requestedLocale();
        $content = $this->menuContent($id, $locale);

        return $content === null
            ? ['status' => 404, 'error' => 'Menu not found']
            : ['data' => $this->menuOf($content)->toArray()];
    }

    /**
     * Create or replace a whole menu — the editor saves the entire item list at
     * once, so there is no partial update to reconcile.
     *
     * @return array<string, mixed>
     */
    public function put(string $id): array
    {
        $body = $this->jsonBody();

        try {
            // Building the Menu is where every target is validated; a bad one
            // throws here, before anything is written.
            $menu = Menu::create(
                $id,
                is_string($body['name'] ?? null) ? $body['name'] : $id,
                $this->itemsFromBody($body['items'] ?? []),
            );
        } catch (InvalidArgumentException $e) {
            return ['status' => 400, 'error' => $e->getMessage()];
        }

        // Menu::create has already accepted the id as a slug, so this key is
        // always well-formed — keyFor cannot return null here.
        $key = $this->keyFor($menu->id(), $this->requestedLocale());
        if ($key === null) {
            return ['status' => 400, 'error' => 'Invalid menu id'];
        }
        $existing = $this->content->get($key);

        $payload = ['name' => $menu->name(), 'items' => $this->itemsToArray($menu->items())];

        if ($existing === null) {
            $this->content->save(Content::create($key, $payload));
        } else {
            // Replace name and items outright; merging would leave a removed
            // item behind, which is the one thing "save the whole list" must not
            // do. `items` is always sent, so it always overwrites.
            $this->content->save($existing->update($payload));
        }

        return ['status' => 200, 'data' => $menu->toArray()];
    }

    /**
     * Resolve a menu for rendering: label plus a ready-to-use href, with
     * external links flagged so a template can mark them.
     *
     * This is the one method the public renderer calls. It returns only safe,
     * finished strings — an internal slug has become a same-origin path and an
     * external target has already been proven to be an http(s) URL — so a
     * template can drop each `href` straight into the markup.
     *
     * The signature is:
     *   resolvedItems(string $menuId, ?string $locale = null): array
     *
     * `$locale` is the language the page is being rendered in. A bare internal
     * slug resolves against it (`home` → `/de/home` on the German site); a
     * target that names its own locale (`de/about`) keeps that regardless. The
     * default locale is never prefixed, matching the public router's
     * `/{slug}` vs `/{locale}/{slug}` convention.
     *
     * A menu that does not exist resolves to an empty list — an empty nav, not
     * an error, because a page must still render when its menu was never built.
     *
     * @return list<array{label: string, href: string, external: bool, children?: array<int, array{label: string, href: string, external: bool}>}>
     */
    public function resolvedItems(string $menuId, ?string $locale = null): array
    {
        $content = $this->menuContent($menuId, $locale);
        if ($content === null) {
            return [];
        }

        $renderLocale = Locale::tryFromString($locale) ?? $this->content->defaultLocale();

        return array_map(
            fn (MenuItem $item): array => $this->resolveItem($item, $renderLocale),
            $this->menuOf($content)->items()
        );
    }

    /* -------------------------------------------------------- helpers -- */

    /**
     * @return array{label: string, href: string, external: bool, children?: array<int, mixed>}
     */
    private function resolveItem(MenuItem $item, Locale $renderLocale): array
    {
        $resolved = [
            'label' => $item->label(),
            'href' => $this->hrefFor($item, $renderLocale),
            'external' => $item->isExternal(),
        ];

        if ($item->children() !== []) {
            $resolved['children'] = array_map(
                fn (MenuItem $child): array => $this->resolveItem($child, $renderLocale),
                $item->children()
            );
        }

        return $resolved;
    }

    private function hrefFor(MenuItem $item, Locale $renderLocale): string
    {
        // An external URL is emitted as-is; it was validated as http(s) at
        // construction, so it is safe to place in an href verbatim.
        if ($item->isExternal()) {
            return $item->target();
        }

        // An anchor with no page part points within the page being viewed, so it
        // is emitted as a bare fragment rather than being resolved against a
        // slug it does not have. That is what a one-page navigation means, and
        // prefixing it with a path would send every link to the home page.
        $fragment = $item->fragment();
        if ($item->slug() === null) {
            return '#' . (string) $fragment;
        }

        // A target that named its own locale keeps it; otherwise the page's
        // render locale decides. The default locale gets no prefix, matching the
        // public router.
        $localeCode = $item->localeCode() ?? $renderLocale->code;
        $slug = (string) $item->slug();

        $path = $localeCode === $this->content->defaultLocale()->code
            ? '/' . $slug
            : '/' . $localeCode . '/' . $slug;

        return $fragment === null ? $path : $path . '#' . $fragment;
    }

    /**
     * @param mixed $items
     * @return list<MenuItem>
     */
    private function itemsFromBody(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = MenuItem::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * @param list<MenuItem> $items
     * @return list<array<string, mixed>>
     */
    private function itemsToArray(array $items): array
    {
        return array_map(static fn (MenuItem $i): array => $i->toArray(), $items);
    }

    private function menuOf(Content $content): Menu
    {
        return Menu::fromArray([
            'id' => $content->slug(),
            'name' => $content->data['name'] ?? $content->slug(),
            'items' => $content->data['items'] ?? [],
        ]);
    }

    /**
     * The document key for a menu, or null when the id cannot name one.
     *
     * Menus are not translated documents — there is one "main" menu whose items
     * can point at any language, exactly as accounts and media are stored under
     * the default locale rather than once per language. A `null` here keeps an
     * id containing a colon (which would otherwise make {@see ContentKey}
     * misparse the composite key) a clean miss rather than a 500.
     */
    /**
     * Where a menu lives, per language.
     *
     * Menus were keyed to the default locale alone, so a bilingual site had one
     * navigation for both languages — the header being the first thing a
     * visitor reads, and the only part of a page that could not be translated.
     * They are keyed like pages now, and read with the same fallback: an
     * untranslated menu shows the default language's rather than nothing.
     */
    /**
     * A menu in the language asked for, or the default language's when that one
     * has not been translated — the rule pages follow, so a half-translated site
     * shows a usable header rather than an empty one.
     */
    private function menuContent(string $id, ?string $locale): ?Content
    {
        $key = $this->keyFor($id, $locale);
        if ($key === null) {
            return null;
        }

        $content = $this->content->get($key);
        if ($content !== null) {
            return $content;
        }

        $fallback = $this->keyFor($id, null);

        return $fallback === null ? null : $this->content->get($fallback);
    }

    /** The `?locale=` a request named, if any. */
    private function requestedLocale(): ?string
    {
        $raw = $_GET['locale'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function keyFor(string $id, ?string $locale = null): ?ContentKey
    {
        if (str_contains($id, ':')) {
            return null;
        }

        $code = (Locale::tryFromString($locale) ?? $this->content->defaultLocale())->code;

        try {
            return ContentKey::fromString('menu:' . $code . ':' . $id);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return $_POST;
        }

        $data = json_decode($input, true);

        return is_array($data) ? $data : [];
    }
}
