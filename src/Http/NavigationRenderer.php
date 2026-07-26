<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * Renders the public site header: an optional brand, and the main navigation
 * with active-page marking, nested dropdowns, and a mobile toggle.
 *
 * Pulled out of the HTTP kernel because a navigation bar is a real piece of UI
 * with real rules — which item is current, how a submenu opens, what a bot sees
 * — and those are worth pinning one case at a time rather than growing inside a
 * method on the god-object. It takes already-resolved items (each a safe href, a
 * label, an external flag, and optional children) plus the current page's href
 * and the site name, and returns markup. It speaks no HTTP and reads no storage.
 *
 * Two things it is careful about:
 *
 *  - Everything an editor typed — labels, the site name — is escaped, because it
 *    lands in HTML here. Hrefs were validated to a slug or an http(s) URL when
 *    the menu was saved, but are escaped too, since an attribute is an attribute.
 *  - It degrades without JavaScript. The nav is fully present and usable in the
 *    markup; the inline script only *enhances* it — collapsing behind a toggle on
 *    narrow screens and turning submenus into click/keyboard disclosures. With no
 *    script the whole menu simply shows, which is a worse layout but not a broken
 *    one.
 */
final class NavigationRenderer
{
    private readonly BasePath $urlBase;

    /**
     * @param BasePath|null $urlBase The prefix this installation is served under.
     *        Menu hrefs are stored as site paths (`/about`) — which is what makes
     *        a menu portable between installations — so the prefix goes on here,
     *        at the moment they become links. Null means the domain root, leaving
     *        the markup exactly as it was before prefixes existed.
     */
    public function __construct(?BasePath $urlBase = null)
    {
        $this->urlBase = $urlBase ?? BasePath::root();
    }

    /**
     * @param list<array{label: string, href: string, external: bool, children?: array<int, array<string, mixed>>}> $items
     * @param string $currentHref The href of the page being rendered, so the
     *                            matching nav item can be marked current.
     * @param string|null $brand The site name, shown as a home link, or null/empty
     *                          for no brand.
     */
    public function render(array $items, string $currentHref, ?string $brand = null): string
    {
        $brand = $brand !== null ? trim($brand) : '';

        // Nothing to show at all: no menu and no name. A site that built neither
        // gets no header rather than an empty bar.
        if ($items === [] && $brand === '') {
            return '';
        }

        $brandHtml = $brand !== ''
            ? '<a class="cms-brand" href="' . $this->escape($this->urlBase->url('/')) . '">'
                . $this->escape($brand) . '</a>'
            : '';

        $navHtml = '';
        if ($items !== []) {
            $list = '';
            foreach ($items as $item) {
                $list .= $this->renderItem($item, $currentHref);
            }

            $navHtml =
                '<button type="button" class="cms-nav-toggle" aria-expanded="false"'
                . ' aria-controls="cms-nav-menu">'
                . '<span class="cms-nav-toggle-bars" aria-hidden="true"></span>'
                . '<span class="cms-visually-hidden">Menu</span>'
                . '</button>'
                . '<nav class="cms-nav" aria-label="Main">'
                . '<ul id="cms-nav-menu" class="cms-nav-list">' . $list . '</ul>'
                . '</nav>';
        }

        return '<header class="cms-header">'
            . '<div class="cms-header-inner">' . $brandHtml . $navHtml . '</div>'
            . '</header>'
            . ($items !== [] ? '<script>' . self::NAV_ENHANCE_JS . '</script>' : '')
            . "\n    ";
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderItem(array $item, string $currentHref): string
    {
        $label = $this->escape((string) ($item['label'] ?? ''));
        $href = (string) ($item['href'] ?? '');

        $external = (bool) ($item['external'] ?? false);

        // The link as it will be written. An external href names its own host and
        // is left exactly as stored; an on-site one gains this installation's
        // prefix. Note the *unprefixed* $href is what the current-page comparison
        // below uses — both sides of it are site paths, and prefixing one of them
        // would mean no item is ever marked current.
        $hrefAttr = $this->escape($external ? $href : $this->urlBase->url($href));
        // An external link opens in a new tab and does not hand the opener
        // window to the destination.
        $rel = $external ? ' rel="noopener noreferrer" target="_blank"' : '';

        // The current page is marked for both assistive tech and styling. Only an
        // on-site href can be current; an external link never is.
        $current = !$external && $href !== '' && $href === $currentHref;
        $ariaCurrent = $current ? ' aria-current="page"' : '';

        $children = (isset($item['children']) && is_array($item['children'])) ? $item['children'] : [];
        $hasChildren = $children !== [];

        $itemClass = 'cms-nav-item'
            . ($hasChildren ? ' cms-nav-item--has-children' : '')
            . ($current ? ' cms-nav-item--current' : '');

        $inner = '<a href="' . $hrefAttr . '"' . $rel . $ariaCurrent . '>' . $label . '</a>';

        if ($hasChildren) {
            // A separate control opens the submenu, so the link itself still goes
            // to its page. Labelled by the parent so a screen reader announces
            // which menu it opens.
            $inner .= '<button type="button" class="cms-nav-subtoggle" aria-expanded="false">'
                . '<span class="cms-visually-hidden">Submenu for ' . $label . '</span>'
                . '</button>';

            $childList = '';
            foreach ($children as $child) {
                if (is_array($child)) {
                    $childList .= $this->renderItem($child, $currentHref);
                }
            }
            $inner .= '<ul class="cms-nav-children">' . $childList . '</ul>';
        }

        return '<li class="' . $itemClass . '">' . $inner . '</li>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Enhances the static nav: marks the page as script-capable (so CSS can
     * switch to the collapsed layout), wires the mobile toggle, and turns each
     * submenu into a disclosure that opens on click and closes on Escape or an
     * outside click. Kept small and dependency-free; the nav works without it.
     */
    private const NAV_ENHANCE_JS = <<<'JS'
        (function () {
          var root = document.documentElement;
          root.classList.add('cms-js');
          var header = document.currentScript.previousElementSibling;
          if (!header || !header.classList.contains('cms-header')) { return; }

          var toggle = header.querySelector('.cms-nav-toggle');
          var nav = header.querySelector('.cms-nav');
          function setOpen(open) {
            header.classList.toggle('cms-nav-open', open);
            if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
          }
          if (toggle) {
            toggle.addEventListener('click', function () {
              setOpen(!header.classList.contains('cms-nav-open'));
            });
          }

          var subtoggles = header.querySelectorAll('.cms-nav-subtoggle');
          function closeSubs(except) {
            subtoggles.forEach(function (b) {
              if (b !== except) {
                b.setAttribute('aria-expanded', 'false');
                b.parentNode.classList.remove('cms-nav-item--open');
              }
            });
          }
          subtoggles.forEach(function (b) {
            b.addEventListener('click', function () {
              var open = b.getAttribute('aria-expanded') === 'true';
              closeSubs(b);
              b.setAttribute('aria-expanded', open ? 'false' : 'true');
              b.parentNode.classList.toggle('cms-nav-item--open', !open);
            });
          });

          document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeSubs(null); setOpen(false); }
          });
          document.addEventListener('click', function (e) {
            if (!header.contains(e.target)) { closeSubs(null); setOpen(false); }
          });
        })();
        JS;
}
