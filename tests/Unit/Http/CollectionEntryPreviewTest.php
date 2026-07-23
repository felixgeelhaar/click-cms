<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Http\CollectionsController;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * Preview for a collection entry: because an entry has no server-rendered page,
 * a preview is the draft itself, delivered as JSON through a signed link, for a
 * front-end preview environment to render. Minting the link is authenticated and
 * permission-gated; reading it is anonymous but gated by the signature.
 */
final class CollectionEntryPreviewTest extends TestCase
{
    private string $base;
    private CollectionsController $controller;
    /** @var array<string, mixed> */
    private array $user = ['username' => 'ed', 'role' => 'admin'];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-entry-preview-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/collections', 0o700, true);
        file_put_contents($this->base . '/collections/post.json', json_encode([
            'label' => 'Blog posts',
            'titleField' => 'title',
            'fields' => [['name' => 'title', 'type' => 'text', 'required' => true]],
        ]));

        $versions = new JsonVersionStore($this->base . '/versions');
        $storage = new VersioningStorage(new JsonStorage($this->base . '/content'), $versions);
        Publishable::register(['post']);

        $content = new ContentService($storage);
        $types = new JsonCollectionTypeRepository($this->base . '/collections');

        $this->controller = new CollectionsController(
            new CollectionService($content, $types, new SectionValidator()),
            new ReferenceResolver($content, $types),
            fn (): array => $this->user,
            null,
            new PreviewLinks($this->base . '/preview-secret'),
        );

        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        $_GET = [];
        $_POST = [];
        $this->rrmdir($this->base);
    }

    private function createDraft(string $slug, string $title): void
    {
        $_POST = ['slug' => $slug, 'values' => ['title' => $title]];
        $this->controller->createEntry('post');
        $_POST = [];
    }

    public function testAMintedLinkReadsBackTheDraft(): void
    {
        // A draft that is not published — the exact thing a preview shows.
        $this->createDraft('secret-post', 'Secret Post');

        $mint = $this->controller->createEntryPreviewLink('post', 'secret-post');
        $this->assertArrayHasKey('data', $mint);
        $this->assertArrayHasKey('url', $mint['data']);

        // Pull the token out of the minted URL and present it as an anonymous
        // reader would.
        parse_str(parse_url($mint['data']['url'], PHP_URL_QUERY) ?: '', $query);
        $this->user = [];               // anonymous from here on
        $_GET = ['token' => $query['token']];

        $preview = $this->controller->previewEntry('post', 'secret-post');
        $this->assertSame('Secret Post', $preview['data']['data']['title']);
        $this->assertTrue($preview['preview']);
        // It marks itself uncacheable and unindexable.
        $this->assertSame('no-store, private', $preview['headers']['Cache-Control']);
    }

    public function testAnAnonymousReaderWithoutATokenGetsA404(): void
    {
        $this->createDraft('secret-post', 'Secret Post');

        $this->user = [];
        $_GET = [];
        $result = $this->controller->previewEntry('post', 'secret-post');

        $this->assertSame(404, $result['status']);
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->createDraft('secret-post', 'Secret Post');

        $this->user = [];
        $_GET = ['token' => 'not-a-valid-token'];
        $result = $this->controller->previewEntry('post', 'secret-post');

        $this->assertSame(404, $result['status']);
    }

    public function testASignedInEditorMayPreviewWithoutAToken(): void
    {
        $this->createDraft('secret-post', 'Secret Post');

        // Still signed in (setUp's user), no token.
        $_GET = [];
        $result = $this->controller->previewEntry('post', 'secret-post');

        $this->assertSame('Secret Post', $result['data']['data']['title']);
    }

    public function testMintingRequiresAuthentication(): void
    {
        $this->createDraft('secret-post', 'Secret Post');

        $this->user = [];
        $result = $this->controller->createEntryPreviewLink('post', 'secret-post');

        $this->assertSame(401, $result['status']);
    }

    public function testMintingRequiresThePreviewCapability(): void
    {
        $this->createDraft('secret-post', 'Secret Post');

        // A viewer may read but not share a preview.
        $this->user = ['username' => 'v', 'role' => 'viewer'];
        $result = $this->controller->createEntryPreviewLink('post', 'secret-post');

        $this->assertSame(403, $result['status']);
    }

    public function testMintingAnUnknownEntryIs404(): void
    {
        $result = $this->controller->createEntryPreviewLink('post', 'no-such-entry');
        $this->assertSame(404, $result['status']);
    }

    public function testWithoutAPreviewServiceMintingReportsItAbsent(): void
    {
        $content = new ContentService(new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        ));
        $types = new JsonCollectionTypeRepository($this->base . '/collections');
        $controller = new CollectionsController(
            new CollectionService($content, $types, new SectionValidator()),
            new ReferenceResolver($content, $types),
            fn (): array => $this->user,
        );

        $result = $controller->createEntryPreviewLink('post', 'secret-post');
        $this->assertSame(501, $result['status']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
