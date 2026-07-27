<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain\Menu;

use Click\Cms\Domain\Menu\Menu;
use Click\Cms\Domain\Menu\MenuItem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The menu value object, the piece that decides what a navigation link is
 * allowed to point at.
 *
 * The security-critical assertion here is that a target is validated at
 * construction: a rendered nav is HTML the site emits with an editor's string
 * inside an `href`, so an unvalidated `javascript:` target is a stored-XSS
 * vector. The domain refuses it before it can ever reach storage, let alone a
 * template.
 */
final class MenuTest extends TestCase
{
    /* --------------------------------------------------- round-tripping -- */

    public function testAMenuRoundTripsThroughToArrayAndFromArray(): void
    {
        $menu = Menu::create('main', 'Main navigation', [
            MenuItem::create('Home', 'home'),
            MenuItem::create('About us', 'de/about'),
            MenuItem::create('Blog', 'https://blog.example.com'),
        ]);

        $rebuilt = Menu::fromArray($menu->toArray());

        $this->assertSame($menu->toArray(), $rebuilt->toArray());
        $this->assertSame('main', $rebuilt->id());
        $this->assertSame('Main navigation', $rebuilt->name());
        $this->assertCount(3, $rebuilt->items());
    }

    public function testAMenuWithNoItemsIsValid(): void
    {
        $menu = Menu::create('footer', 'Footer');

        $this->assertSame([], $menu->items());
        // And it survives a round-trip as an empty nav rather than becoming null.
        $this->assertSame([], Menu::fromArray($menu->toArray())->items());
    }

    public function testItemOrderIsPreservedThroughAReSave(): void
    {
        $menu = Menu::create('main', 'Main', [
            MenuItem::create('First', 'one'),
            MenuItem::create('Second', 'two'),
            MenuItem::create('Third', 'three'),
        ]);

        $labels = array_map(
            static fn (MenuItem $i): string => $i->label(),
            Menu::fromArray($menu->toArray())->items()
        );

        $this->assertSame(['First', 'Second', 'Third'], $labels);
    }

    /* ------------------------------------------------- target validation -- */

    public function testAJavascriptTargetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MenuItem::create('Click me', 'javascript:alert(document.cookie)');
    }

    public function testAJavascriptTargetDisguisedWithSlashesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MenuItem::create('Click me', 'javascript:alert(1)//example.com');
    }

    public function testNonHttpSchemesAreRejected(): void
    {
        foreach (['data:text/html,<script>', 'ftp://host/f', 'mailto:a@b.c', '//evil.example.com'] as $target) {
            try {
                MenuItem::create('X', $target);
                $this->fail("target \"{$target}\" should have been rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnEmptyLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MenuItem::create('   ', 'home');
    }

    public function testAMalformedSlugIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MenuItem::create('Bad', 'Not A Slug!');
    }

    /* -------------------------------------------------- classification -- */

    public function testAnHttpsUrlIsClassifiedExternal(): void
    {
        $item = MenuItem::create('Docs', 'https://example.com/docs');

        $this->assertTrue($item->isExternal());
        $this->assertNull($item->slug());
    }

    public function testAPlainSlugIsInternal(): void
    {
        $item = MenuItem::create('Home', 'home');

        $this->assertFalse($item->isExternal());
        $this->assertSame('home', $item->slug());
        $this->assertNull($item->localeCode());
    }

    public function testASlugMayCarryAnExplicitLocale(): void
    {
        $item = MenuItem::create('Über uns', 'de/about');

        $this->assertFalse($item->isExternal());
        $this->assertSame('de', $item->localeCode());
        $this->assertSame('about', $item->slug());
    }

    /* -------------------------------------------------------- nesting -- */

    public function testATopItemMayHaveOneLevelOfChildren(): void
    {
        $menu = Menu::create('main', 'Main', [
            MenuItem::create('Products', 'products', [
                MenuItem::create('Widgets', 'widgets'),
                MenuItem::create('Gadgets', 'gadgets'),
            ]),
        ]);

        $rebuilt = Menu::fromArray($menu->toArray());
        $children = $rebuilt->items()[0]->children();

        $this->assertCount(2, $children);
        $this->assertSame('Widgets', $children[0]->label());
        $this->assertSame(['Widgets', 'Gadgets'], array_map(
            static fn (MenuItem $i): string => $i->label(),
            $children
        ));
    }

    public function testNestingDeeperThanOneLevelIsRejected(): void
    {
        // A grandchild would be a second nesting level. The renderer only draws
        // two, so accepting a third would silently drop content.
        $this->expectException(InvalidArgumentException::class);
        MenuItem::create('Products', 'products', [
            MenuItem::create('Widgets', 'widgets', [
                MenuItem::create('Too deep', 'deep'),
            ]),
        ]);
    }

    public function testAChildTargetIsValidatedToo(): void
    {
        // The injection guard must not have a hole one level down.
        $this->expectException(InvalidArgumentException::class);
        MenuItem::create('Products', 'products', [
            MenuItem::create('Evil', 'javascript:alert(1)'),
        ]);
    }

    public function testAMenuIdMustBeASlug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Menu::create('Not A Menu Id', 'Name');
    }

    /* ---------------------------------------------------- anchors -- */

    /**
     * A one-page site's navigation is anchors, and until now none of it could
     * be expressed here: a target had to be a page slug or an absolute URL, so
     * "Contact" pointing at #contact simply could not be saved. That is the
     * common case for the sites this CMS is aimed at, not an exotic one.
     */
    public function testAnAnchorIsAValidTarget(): void
    {
        $item = MenuItem::create('Kontakt', '#contact');

        $this->assertSame('#contact', $item->target());
        $this->assertFalse($item->isExternal());
        $this->assertTrue($item->isAnchor());
    }

    /** A section of a particular page, rather than of whatever page is open. */
    public function testAPageAnchorKeepsBothParts(): void
    {
        $item = MenuItem::create('Team', 'about#team');

        $this->assertSame('about', $item->slug());
        $this->assertSame('team', $item->fragment());
        $this->assertFalse($item->isExternal());
    }

    public function testALocalePrefixedPageAnchorWorks(): void
    {
        $item = MenuItem::create('Team', 'de/about#team');

        $this->assertSame('de', $item->localeCode());
        $this->assertSame('about', $item->slug());
        $this->assertSame('team', $item->fragment());
    }

    /**
     * The reason this class exists is that an editor's string lands inside an
     * href. A fragment is a new shape of input, so it gets the same treatment:
     * an id, and nothing that could close the attribute or carry a scheme.
     */
    public function testAHostileFragmentIsRefused(): void
    {
        foreach ([
            '#"onmouseover="alert(1)',
            '#javascript:alert(1)',
            '# space',
            '#<script>',
            "#a'b",
            '#',
        ] as $target) {
            try {
                MenuItem::create('Bad', $target);
                $this->fail("accepted a hostile fragment: {$target}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnAnchorSurvivesTheRoundTripThroughAnArray(): void
    {
        $item = MenuItem::fromArray(MenuItem::create('Kontakt', '#contact')->toArray());

        $this->assertSame('#contact', $item->target());
        $this->assertTrue($item->isAnchor());
    }
}
