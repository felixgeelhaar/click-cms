<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Http\CoreApiRoutes;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The management endpoints for publication, driven the way a request drives
 * them.
 *
 * A permission check that only exists in the service is worth nothing if the
 * route forgets to call it, and the state a UI renders from is worth nothing if
 * it is not in the response — so both are exercised here rather than trusted
 * from the layer below.
 */
final class PublicationRoutesTest extends TestCase
{
    private string $base;
    private CoreApiRoutes $api;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-publication-api-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $_GET = [];
        $_COOKIE = [];

        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        ));
        $this->api = new CoreApiRoutes($this->base, $this->content);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    private function signIn(string $role, string $username = 'ann'): void
    {
        $id = bin2hex(random_bytes(32));
        @mkdir($this->base . '/data/sessions', 0o700, true);

        file_put_contents(
            $this->base . '/data/sessions/' . $id . '.json',
            json_encode([
                'username' => $username,
                'expiresAt' => time() + 3600,
                'lastActivity' => time(),
                'user' => ['username' => $username, 'role' => $role],
            ])
        );

        $_COOKIE[SessionStore::COOKIE] = $id;
    }

    /** @return array<string, mixed> */
    private function createPage(string $slug, string $title = 'A page'): array
    {
        // The endpoint reads its body from php://input, which a test cannot
        // set, so the page is written through the service the endpoint uses.
        $this->content->save(\Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::page($slug),
            ['title' => $title, 'owner' => 'ann']
        ));

        return ['slug' => $slug];
    }

    /* ------------------------------------------------------------ publish -- */

    public function testPublishingMakesThePageReadableWithoutASession(): void
    {
        $this->createPage('home', 'Live text');
        $this->signIn('editor');

        $response = $this->api->publishPage('home');

        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['data']['publication']['published']);
        $this->assertFalse($response['data']['publication']['hasUnpublishedChanges']);

        // Now with no session at all, as a headless front end would read it.
        $_COOKIE = [];
        $this->assertSame('home', $this->api->getPage('home')['data']['slug']);
        $this->assertContains('home', array_column($this->api->listPages()['data'], 'slug'));
    }

    public function testAnUnpublishedPageIsNotFoundWithoutASession(): void
    {
        $this->createPage('secret');

        $this->assertSame(404, $this->api->getPage('secret')['status']);
        $this->assertNotContains('secret', array_column($this->api->listPages()['data'], 'slug'));
    }

    public function testUnpublishingTakesThePageBackOutOfThePublicApi(): void
    {
        $this->createPage('offer');
        $this->signIn('editor');
        $this->api->publishPage('offer');

        $this->assertArrayNotHasKey('error', $this->api->unpublishPage('offer'));

        $_COOKIE = [];
        $this->assertSame(404, $this->api->getPage('offer')['status']);
    }

    /* -------------------------------------------------------- permissions -- */

    /**
     * The check has to be at the route as well as in the service, because the
     * kernel's path rules match on prefixes and `pages` is otherwise readable
     * without a session.
     */
    public function testAnAuthorIsRefusedAtTheEndpoint(): void
    {
        $this->createPage('mine');
        $this->signIn('author', 'ann');

        $response = $this->api->publishPage('mine');

        $this->assertSame(403, $response['status']);

        $_COOKIE = [];
        $this->assertSame(404, $this->api->getPage('mine')['status']);
    }

    public function testAnAnonymousCallerCannotPublish(): void
    {
        $this->createPage('mine');

        $this->assertSame(403, $this->api->publishPage('mine')['status']);
    }

    /* ------------------------------------------------- what the api exposes -- */

    /**
     * The three states a UI asks about, on the endpoints it reads them from.
     * An editor who cannot see that a published page has pending edits will
     * assume their change is live.
     */
    public function testPublicationStateIsReportedToAnEditor(): void
    {
        $this->createPage('home', 'First');
        $this->signIn('editor');

        $state = $this->api->getPage('home')['publication'];
        $this->assertFalse($state['published']);
        $this->assertTrue($state['neverPublished']);

        $this->api->publishPage('home');

        $state = $this->api->getPage('home')['publication'];
        $this->assertTrue($state['published']);
        $this->assertFalse($state['hasUnpublishedChanges']);
        $this->assertNotNull($state['publishedAt']);

        $this->content->save(
            $this->content->draftPage('home')->update(['title' => 'Second'])
        );

        $state = $this->api->getPage('home')['publication'];
        $this->assertTrue($state['published']);
        $this->assertTrue($state['hasUnpublishedChanges']);

        // And in the listing, so an editor can see at a glance what is pending
        // without opening every page.
        $row = array_values(array_filter(
            $this->api->listPages()['data'],
            static fn (array $p): bool => $p['slug'] === 'home'
        ))[0];

        $this->assertTrue($row['publication']['hasUnpublishedChanges']);
    }

    /**
     * Withheld from anonymous callers. They are looking at the live site by
     * definition, and telling them which pages have edits pending leaks the
     * shape of work in progress for no benefit.
     */
    public function testPublicationStateIsNotExposedAnonymously(): void
    {
        $this->createPage('home');
        $this->signIn('editor');
        $this->api->publishPage('home');

        $_COOKIE = [];

        $this->assertArrayNotHasKey('publication', $this->api->getPage('home'));
        $this->assertArrayNotHasKey('publication', $this->api->listPages()['data'][0]);
    }

    /**
     * An editor reading a page gets the copy they are working on, not the copy
     * visitors get — otherwise opening a page they have edited shows them the
     * old text and they save over their own work.
     */
    public function testAnEditorReadsTheWorkingCopyAndAVisitorReadsTheLiveOne(): void
    {
        $this->createPage('home', 'Published text');
        $this->signIn('editor');
        $this->api->publishPage('home');

        $this->content->save(
            $this->content->draftPage('home')->update(['title' => 'Being rewritten'])
        );

        $this->assertSame('Being rewritten', $this->api->getPage('home')['data']['data']['title']);

        $_COOKIE = [];
        $this->assertSame('Published text', $this->api->getPage('home')['data']['data']['title']);
    }
}
