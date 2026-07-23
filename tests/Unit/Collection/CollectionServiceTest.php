<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Collection;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Domain\Publishing\Publishable;
use PHPUnit\Framework\TestCase;

/**
 * Collection entries are ordinary content documents whose type is the
 * collection's id, so the behaviours worth pinning are the ones that differ from
 * a page: entries validate against their collection type's fields, unknown
 * fields are dropped, a draft is separate from what is published, and the same
 * per-owner permission rules apply. The draft/publish and history machinery
 * underneath is a page's and is tested there; here we prove an entry rides it.
 */
final class CollectionServiceTest extends TestCase
{
    private string $base;
    private CollectionService $service;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-collections-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o700, true);
        mkdir($this->base . '/collections', 0o700, true);

        // A minimal collection type on disk, so the repository has something real
        // to load rather than a stub.
        file_put_contents($this->base . '/collections/post.json', json_encode([
            'label' => 'Blog posts',
            'titleField' => 'title',
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'body', 'type' => 'richtext', 'required' => true],
            ],
        ]));

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        );
        // A collection's entries are publishable, exactly as a site's boot would
        // register them, so the draft-and-publish lifecycle applies here too.
        Publishable::register(['post']);

        $this->content = new ContentService($storage);
        $this->service = new CollectionService(
            $this->content,
            new JsonCollectionTypeRepository($this->base . '/collections'),
            new SectionValidator(),
        );
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        $this->rrmdir($this->base);
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

    /** @return array<string, mixed> */
    private function editor(): array
    {
        return ['username' => 'ed', 'role' => 'editor'];
    }

    public function testTheDeclaredTypesAreListed(): void
    {
        $types = $this->service->collectionTypes();
        $this->assertCount(1, $types);
        $this->assertSame('post', $types[0]->id);
        $this->assertSame('Blog posts', $types[0]->label);
    }

    public function testCreatingAnEntryValidatesAgainstTheTypeAndDropsUnknownFields(): void
    {
        $result = $this->service->create('post', ['values' => [
            'title' => 'Hello world',
            'body' => '<p>First post.</p>',
            'colour' => 'not a declared field',
        ]], $this->editor());

        $this->assertSame(201, $result['status']);
        $entry = $result['entry'];
        $this->assertNotNull($entry);
        $this->assertSame('hello-world', $entry->slug());
        $this->assertSame('Hello world', $entry->data['title']);
        // The undeclared field never reached storage.
        $this->assertArrayNotHasKey('colour', $entry->data);
        // Ownership is recorded for the per-author permission checks.
        $this->assertSame('ed', $entry->data['owner']);
    }

    public function testARequiredFieldMissingIsA422WithFieldErrors(): void
    {
        $result = $this->service->create('post', ['values' => ['title' => 'No body']], $this->editor());

        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('body', $result['errors']);
        $this->assertNull($result['entry']);
    }

    public function testAnUnknownCollectionIsA404(): void
    {
        $result = $this->service->create('widgets', ['values' => ['title' => 'x']], $this->editor());
        $this->assertSame(404, $result['status']);
    }

    public function testAViewerCannotCreate(): void
    {
        $result = $this->service->create(
            'post',
            ['values' => ['title' => 'T', 'body' => '<p>b</p>']],
            ['username' => 'v', 'role' => 'viewer']
        );
        $this->assertSame(403, $result['status']);
    }

    public function testAnEntryIsADraftUntilPublished(): void
    {
        $this->service->create('post', ['values' => ['title' => 'Draft', 'body' => '<p>b</p>']], $this->editor());

        // Working copy exists; nothing is published yet.
        $this->assertNotNull($this->service->find('post', 'draft'));
        $this->assertNull($this->service->findPublished('post', 'draft'));
        $this->assertSame([], $this->service->published('post'));

        $published = $this->service->publish('post', 'draft', $this->editor());
        $this->assertSame(200, $published['status']);
        $this->assertNotNull($this->service->findPublished('post', 'draft'));
        $this->assertCount(1, $this->service->published('post'));
    }

    public function testUpdatingCannotRewriteSlugOrOwner(): void
    {
        $this->service->create('post', ['values' => ['title' => 'Orig', 'body' => '<p>b</p>']], $this->editor());

        $result = $this->service->update('post', 'orig', ['values' => [
            'title' => 'Edited',
            'body' => '<p>b2</p>',
            'slug' => 'hijacked',
            'owner' => 'someone-else',
        ]], $this->editor());

        $this->assertSame(200, $result['status']);
        $entry = $this->service->find('post', 'orig');
        $this->assertNotNull($entry, 'the entry keeps its original address');
        $this->assertSame('Edited', $entry->data['title']);
        $this->assertSame('orig', $entry->data['slug']);
        $this->assertSame('ed', $entry->data['owner']);
        $this->assertNull($this->service->find('post', 'hijacked'));
    }

    public function testDeletingRemovesTheEntry(): void
    {
        $this->service->create('post', ['values' => ['title' => 'Bye', 'body' => '<p>b</p>']], $this->editor());
        $this->assertNotNull($this->service->find('post', 'bye'));

        $result = $this->service->delete('post', 'bye', $this->editor());
        $this->assertSame(200, $result['status']);
        $this->assertNull($this->service->find('post', 'bye'));
    }

    public function testAnAuthorCannotEditAnEntryOwnedByAnother(): void
    {
        $this->service->create('post', ['values' => ['title' => 'Mine', 'body' => '<p>b</p>']], $this->editor());

        $result = $this->service->update(
            'post',
            'mine',
            ['values' => ['title' => 'Hijack', 'body' => '<p>b</p>']],
            ['username' => 'someone', 'role' => 'author']
        );
        $this->assertSame(403, $result['status']);
    }
}
