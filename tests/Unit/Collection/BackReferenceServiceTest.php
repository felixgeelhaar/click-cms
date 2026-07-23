<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Collection;

use Click\Cms\Application\Collection\BackReferenceService;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The reverse of a reference: which entries point at a given item. Scanned from
 * the reference schema, so only types that could point at the target are read.
 */
final class BackReferenceServiceTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private BackReferenceService $service;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-backref-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/collections', 0o700, true);

        // Authors, and posts that reference an author (single) and co-authors
        // (many). A third type references nothing, to prove it is skipped.
        file_put_contents($this->base . '/collections/author.json', json_encode([
            'label' => 'Authors',
            'titleField' => 'name',
            'fields' => [['name' => 'name', 'type' => 'text', 'required' => true]],
        ]));
        file_put_contents($this->base . '/collections/post.json', json_encode([
            'label' => 'Posts',
            'titleField' => 'title',
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'author', 'type' => 'reference', 'references' => 'author'],
                ['name' => 'contributors', 'type' => 'reference', 'references' => 'author', 'multiple' => true],
            ],
        ]));
        file_put_contents($this->base . '/collections/note.json', json_encode([
            'label' => 'Notes',
            'titleField' => 'title',
            'fields' => [['name' => 'title', 'type' => 'text', 'required' => true]],
        ]));

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        );
        Publishable::register(['author', 'post', 'note']);

        $this->content = new ContentService($storage);
        $this->service = new BackReferenceService(
            new JsonCollectionTypeRepository($this->base . '/collections'),
            $this->content,
        );
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        $this->rrmdir($this->base);
    }

    private function saveEntry(string $type, string $slug, array $data): void
    {
        $this->content->save(Content::create(
            ContentKey::for($type, $slug),
            ['slug' => $slug] + $data,
        ));
    }

    public function testASingleReferenceIsFound(): void
    {
        $this->saveEntry('author', 'ada', ['name' => 'Ada']);
        $this->saveEntry('post', 'hello', ['title' => 'Hello', 'author' => 'ada']);
        $this->saveEntry('post', 'other', ['title' => 'Other', 'author' => 'grace']);

        $refs = $this->service->referencesTo('author', 'ada');

        $this->assertCount(1, $refs);
        $this->assertSame('post', $refs[0]['type']);
        $this->assertSame('hello', $refs[0]['slug']);
        $this->assertSame('Hello', $refs[0]['title']);
        $this->assertSame('author', $refs[0]['field']);
    }

    public function testAManyValuedReferenceMatchesOnMembership(): void
    {
        $this->saveEntry('author', 'ada', ['name' => 'Ada']);
        $this->saveEntry('post', 'team', ['title' => 'Team', 'contributors' => ['grace', 'ada']]);

        $refs = $this->service->referencesTo('author', 'ada');

        $this->assertCount(1, $refs);
        $this->assertSame('contributors', $refs[0]['field']);
    }

    public function testAnEntryReferencedByTwoFieldsIsReportedForEach(): void
    {
        $this->saveEntry('author', 'ada', ['name' => 'Ada']);
        $this->saveEntry('post', 'solo', ['title' => 'Solo', 'author' => 'ada', 'contributors' => ['ada']]);

        $refs = $this->service->referencesTo('author', 'ada');

        $fields = array_map(static fn ($r): string => $r['field'], $refs);
        sort($fields);
        $this->assertSame(['author', 'contributors'], $fields);
    }

    public function testAnUnreferencedItemHasNoBackReferences(): void
    {
        $this->saveEntry('author', 'lonely', ['name' => 'Lonely']);
        $this->saveEntry('post', 'hello', ['title' => 'Hello', 'author' => 'ada']);

        $this->assertSame([], $this->service->referencesTo('author', 'lonely'));
    }

    public function testDraftsAreIncluded(): void
    {
        // A working copy that was never published still counts as a referrer.
        $this->saveEntry('post', 'draft', ['title' => 'Draft', 'author' => 'ada']);

        $refs = $this->service->referencesTo('author', 'ada');
        $this->assertCount(1, $refs);
        $this->assertSame('draft', $refs[0]['slug']);
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
