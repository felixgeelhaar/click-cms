<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\PagesController;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

final class DraftRenderPreviewTest extends TestCase
{
    private string $base;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-draft-render-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data/sessions', 0o700, true);
        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        ));
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    public function testItRendersValidatedDraftInMemoryWithoutChangingTheSavedWorkingCopy(): void
    {
        $saved = Content::create(ContentKey::page('draft-home'), ['title' => 'Saved title', 'sections' => []]);
        $this->content->save($saved);
        $this->signIn('editor');
        $rendered = null;
        $api = new PagesController($this->base, $this->content, previewRenderer: function (Content $page) use (&$rendered): string {
            $rendered = $page;
            return '<!doctype html><html><head></head><body>' . htmlspecialchars($page->title()) . '</body></html>';
        });

        $response = $api->renderDraftPreview('draft-home', [
            'title' => 'Unsaved title',
            'sections' => [],
            'seo' => ['metaTitle' => 'Unsaved SEO title', 'unknown' => 'discard me'],
        ]);

        $this->assertTrue($response['raw']);
        $this->assertSame('Unsaved title', $rendered?->title());
        $this->assertSame('Unsaved SEO title', $rendered?->data['seo']['metaTitle']);
        $this->assertArrayNotHasKey('unknown', $rendered?->data['seo'] ?? []);
        $this->assertStringContainsString('Unsaved title', $response['html']);
        $this->assertSame('Saved title', $this->content->draft(ContentKey::page('draft-home'))?->title());
        $this->assertStringContainsString("script-src 'none'", $response['headers']['Content-Security-Policy']);
        $this->assertSame('no-store, private', $response['headers']['Cache-Control']);
    }

    public function testItRejectsMissingSessionAndDoesNotRender(): void
    {
        $api = new PagesController($this->base, $this->content, previewRenderer: static fn (): string => 'unsafe');
        $response = $api->renderDraftPreview('new-page', ['title' => 'New', 'sections' => []]);

        $this->assertSame(401, $response['status']);
    }

    public function testAuthorsCanOnlyPreviewTheirOwnSavedPage(): void
    {
        $this->content->save(Content::create(ContentKey::page('others-page'), ['title' => 'Saved', 'owner' => 'grace']));
        $this->signIn('author');
        $api = new PagesController($this->base, $this->content, previewRenderer: static fn (): string => '<html><head></head></html>');

        $response = $api->renderDraftPreview('others-page', ['title' => 'Changed', 'sections' => []]);

        $this->assertSame(403, $response['status']);
    }

    public function testInvalidSectionsAreRejectedBeforeRendering(): void
    {
        $this->signIn('editor');
        $api = new PagesController($this->base, $this->content, previewRenderer: static fn (): string => 'must not render');

        $response = $api->renderDraftPreview('new-page', [
            'title' => 'New',
            'sections' => [['type' => 'not-a-declared-section', 'values' => []]],
        ]);

        $this->assertSame(422, $response['status']);
    }

    public function testInvalidSlugReturnsBadRequestInsteadOfThrowing(): void
    {
        $this->signIn('editor');
        $api = new PagesController($this->base, $this->content, previewRenderer: static fn (): string => 'must not render');

        $response = $api->renderDraftPreview('invalid:slug', ['title' => 'New', 'sections' => []]);

        $this->assertSame(400, $response['status']);
    }

    public function testViewerCannotRenderAnInMemoryDraft(): void
    {
        $this->signIn('viewer');
        $api = new PagesController($this->base, $this->content, previewRenderer: static fn (): string => 'must not render');

        $this->assertSame(403, $api->renderDraftPreview('new-page', ['title' => 'New', 'sections' => []])['status']);
    }

    private function signIn(string $role, string $username = 'ann'): void
    {
        $id = bin2hex(random_bytes(32));
        file_put_contents($this->base . '/data/sessions/' . $id . '.json', json_encode([
            'expiresAt' => time() + 3600,
            'lastActivity' => time(),
            'user' => ['username' => $username, 'role' => $role],
        ]));
        $_COOKIE[SessionStore::COOKIE] = $id;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
