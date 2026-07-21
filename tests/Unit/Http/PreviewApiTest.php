<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\CoreApiRoutes;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * The management endpoints that surround preview: minting a link, and what the
 * page endpoints will say to somebody with no session.
 */
final class PreviewApiTest extends TestCase
{
    private string $base;
    private CoreApiRoutes $api;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-preview-api-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $_COOKIE = [];

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->api = new CoreApiRoutes($this->base, $this->content);

        $this->savePage('secret-plans', 'draft');
        $this->savePage('about', 'published');
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    private function savePage(string $slug, string $status): void
    {
        $this->content->save(Content::create(ContentKey::page($slug), [
            'title' => ucfirst($slug),
            'status' => $status,
        ]));
    }

    private function signIn(string $role): void
    {
        $id = bin2hex(random_bytes(32));
        mkdir($this->base . '/data/sessions', 0o700, true);

        file_put_contents(
            $this->base . '/data/sessions/' . $id . '.json',
            json_encode([
                'expiresAt' => time() + 3600,
                'lastActivity' => time(),
                'user' => ['username' => 'ann', 'role' => $role],
            ])
        );

        $_COOKIE[SessionStore::COOKIE] = $id;
    }

    /* ------------------------------------------------- minting the link -- */

    public function testAnEditorGetsAWorkingLink(): void
    {
        $this->signIn('editor');

        $response = $this->api->createPreviewLink('secret-plans');

        $this->assertArrayNotHasKey('error', $response);
        $this->assertStringStartsWith('/preview/secret-plans?token=', $response['data']['url']);
        $this->assertGreaterThan(time(), $response['data']['expiresAt']);

        parse_str((string) parse_url($response['data']['url'], PHP_URL_QUERY), $query);

        $this->assertTrue(
            (new PreviewLinks($this->base . '/data/preview-secret'))->accepts('secret-plans', $query['token'])
        );
    }

    public function testAnAnonymousCallerCannotMintALink(): void
    {
        $response = $this->api->createPreviewLink('secret-plans');

        $this->assertSame(401, $response['status']);
        $this->assertArrayNotHasKey('data', $response);
    }

    /**
     * A viewer may read unpublished work while signed in, but handing out a
     * link that shows it to somebody with no account at all is a different
     * decision — and it is the role an unrecognised one falls back to.
     */
    public function testAViewerCannotMintALink(): void
    {
        $this->signIn('viewer');

        $this->assertSame(403, $this->api->createPreviewLink('secret-plans')['status']);
    }

    public function testAnUnknownRoleCannotMintALink(): void
    {
        $this->signIn('wizard');

        $this->assertSame(403, $this->api->createPreviewLink('secret-plans')['status']);
    }

    public function testNoLinkIsMintedForAPageThatDoesNotExist(): void
    {
        $this->signIn('admin');

        $this->assertSame(404, $this->api->createPreviewLink('never-written')['status']);
    }

    /* --------------------------------------------- what anonymous can read -- */

    /**
     * This endpoint is deliberately public so a headless front end can read it
     * without an account. It was returning drafts to anyone who asked, which
     * made every unpublished page world-readable through the API even while the
     * preview route guarded them.
     */
    public function testAnonymousListingShowsPublishedPagesOnly(): void
    {
        $slugs = array_column($this->api->listPages()['data'], 'slug');

        $this->assertContains('about', $slugs);
        $this->assertNotContains('secret-plans', $slugs);
    }

    public function testASessionStillSeesEverythingSoEditingKeepsWorking(): void
    {
        $this->signIn('editor');

        $slugs = array_column($this->api->listPages()['data'], 'slug');

        $this->assertContains('about', $slugs);
        $this->assertContains('secret-plans', $slugs);
    }

    /**
     * Not found rather than forbidden: which slugs are being worked on is part
     * of what is being protected.
     */
    public function testAnonymousReadOfADraftIsNotFound(): void
    {
        $response = $this->api->getPage('secret-plans');

        $this->assertSame(404, $response['status']);
        $this->assertArrayNotHasKey('data', $response);
    }

    public function testAnonymousReadOfAPublishedPageStillWorks(): void
    {
        $this->assertSame('about', $this->api->getPage('about')['data']['slug']);
    }

    public function testASessionCanReadADraft(): void
    {
        $this->signIn('author');

        $this->assertSame('secret-plans', $this->api->getPage('secret-plans')['data']['slug']);
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
}
