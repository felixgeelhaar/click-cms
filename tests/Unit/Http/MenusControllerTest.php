<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Http\MenusController;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * Menu management as a core controller, mirroring UsersController.
 *
 * The "may this caller manage menus" gate is the kernel's ApiGuard, and menus
 * are management, so deny-by-default already protects these routes — nothing is
 * added to any public allowlist. What this pins is the controller's own
 * guarantees: a menu round-trips through save and load, a bad target never
 * reaches storage, and the render path ({@see MenusController::resolvedItems()})
 * hands the public renderer only labels and safe hrefs.
 */
final class MenusControllerTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private MenusController $menus;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-menus-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        $_POST = [];

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->menus = new MenusController($this->content);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /** @param array<string, mixed> $body */
    private function put(string $id, array $body): array
    {
        // The router hands the handler only the path param; the body is read
        // inside via jsonBody(), which falls back to $_POST when php://input is
        // empty — as it is under phpunit. This mirrors UsersControllerTest.
        $_POST = $body;
        $r = $this->menus->put($id);
        $_POST = [];
        return $r;
    }

    /* ------------------------------------------------------- round-trip -- */

    public function testAMenuRoundTripsThroughSaveAndLoad(): void
    {
        $saved = $this->put('main', [
            'name' => 'Main navigation',
            'items' => [
                ['label' => 'Home', 'target' => 'home'],
                ['label' => 'About', 'target' => 'about'],
                ['label' => 'Docs', 'target' => 'https://example.com/docs'],
            ],
        ]);
        $this->assertSame(200, $saved['status'] ?? 200);

        $loaded = $this->menus->get('main');
        $items = $loaded['data']['items'];

        $this->assertSame('Main navigation', $loaded['data']['name']);
        $this->assertCount(3, $items);
        $this->assertSame('Home', $items[0]['label']);
        $this->assertSame('https://example.com/docs', $items[2]['target']);
    }

    public function testReorderingIsPreserved(): void
    {
        $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'First', 'target' => 'one'],
            ['label' => 'Second', 'target' => 'two'],
            ['label' => 'Third', 'target' => 'three'],
        ]]);

        // The editor saves the whole list; a reorder is a re-save in a new order.
        $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Third', 'target' => 'three'],
            ['label' => 'First', 'target' => 'one'],
            ['label' => 'Second', 'target' => 'two'],
        ]]);

        $labels = array_column($this->menus->get('main')['data']['items'], 'label');
        $this->assertSame(['Third', 'First', 'Second'], $labels);
    }

    public function testAMenuWithNoItemsIsValid(): void
    {
        $saved = $this->put('footer', ['name' => 'Footer', 'items' => []]);
        $this->assertSame(200, $saved['status'] ?? 200);

        $this->assertSame([], $this->menus->get('footer')['data']['items']);
        $this->assertSame([], $this->menus->resolvedItems('footer', null));
    }

    /* -------------------------------------------------- target rejection -- */

    public function testAJavascriptTargetIsRejectedAndNothingIsStored(): void
    {
        $r = $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Evil', 'target' => 'javascript:alert(document.cookie)'],
        ]]);

        $this->assertSame(400, $r['status']);
        // Refused before storage: the document must not exist at all.
        $this->assertSame(404, $this->menus->get('main')['status']);
    }

    public function testAJavascriptTargetInAChildIsAlsoRejected(): void
    {
        $r = $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Products', 'target' => 'products', 'children' => [
                ['label' => 'Evil', 'target' => 'javascript:alert(1)'],
            ]],
        ]]);

        $this->assertSame(400, $r['status']);
    }

    public function testAnInvalidMenuIdIsRejected(): void
    {
        $this->assertSame(400, $this->put('Not An Id', ['name' => 'x', 'items' => []])['status']);
    }

    /* ------------------------------------------------------- resolution -- */

    public function testResolvedItemsTurnsASlugIntoAnHrefAndMarksExternal(): void
    {
        $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Home', 'target' => 'home'],
            ['label' => 'Docs', 'target' => 'https://example.com/docs'],
        ]]);

        $resolved = $this->menus->resolvedItems('main', null);

        $this->assertSame('Home', $resolved[0]['label']);
        $this->assertSame('/home', $resolved[0]['href']);
        $this->assertFalse($resolved[0]['external']);

        $this->assertSame('https://example.com/docs', $resolved[1]['href']);
        $this->assertTrue($resolved[1]['external']);
    }

    public function testResolvedItemsPrefixesANonDefaultRenderLocale(): void
    {
        $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Home', 'target' => 'home'],
        ]]);

        // Rendering the German site: an internal slug resolves to /de/home.
        $resolved = $this->menus->resolvedItems('main', 'de');
        $this->assertSame('/de/home', $resolved[0]['href']);
    }

    public function testAnExplicitLocaleInTheTargetWinsOverTheRenderLocale(): void
    {
        $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Über', 'target' => 'de/about'],
        ]]);

        // Even while rendering the English site, this link points at German.
        $resolved = $this->menus->resolvedItems('main', null);
        $this->assertSame('/de/about', $resolved[0]['href']);
    }

    public function testResolvedItemsCarriesChildren(): void
    {
        $this->put('main', ['name' => 'Main', 'items' => [
            ['label' => 'Products', 'target' => 'products', 'children' => [
                ['label' => 'Widgets', 'target' => 'widgets'],
            ]],
        ]]);

        $resolved = $this->menus->resolvedItems('main', null);
        $this->assertSame('/products', $resolved[0]['href']);
        $this->assertSame('/widgets', $resolved[0]['children'][0]['href']);
        $this->assertFalse($resolved[0]['children'][0]['external']);
    }

    public function testResolvedItemsOfAMissingMenuIsEmpty(): void
    {
        $this->assertSame([], $this->menus->resolvedItems('does-not-exist', null));
    }

    /* ------------------------------------------------------------- list -- */

    public function testListReturnsAllMenus(): void
    {
        $this->put('main', ['name' => 'Main', 'items' => []]);
        $this->put('footer', ['name' => 'Footer', 'items' => []]);

        $ids = array_column($this->menus->list()['data'], 'id');
        sort($ids);
        $this->assertSame(['footer', 'main'], $ids);
    }

    public function testGettingAMissingMenuIs404(): void
    {
        $this->assertSame(404, $this->menus->get('nope')['status']);
    }
}
