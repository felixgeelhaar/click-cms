<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

final class VersioningStorageTest extends TestCase
{
    private string $contentDir;
    private string $versionsDir;
    private JsonVersionStore $versions;
    private VersioningStorage $storage;
    private ?string $author = 'ada';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/click-cms-versioning-' . bin2hex(random_bytes(6));
        $this->contentDir = $root . '/content';
        $this->versionsDir = $root . '/versions';
        mkdir($this->contentDir, 0o775, true);
        mkdir($this->versionsDir, 0o775, true);

        $this->versions = new JsonVersionStore($this->versionsDir);
        $this->storage = new VersioningStorage(
            new JsonStorage($this->contentDir),
            $this->versions,
            fn (): ?string => $this->author,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree(dirname($this->contentDir));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    public function testASaveIsStillASave(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $this->assertSame('Home', $this->storage->find(ContentKey::page('home'))?->title());
        $this->assertTrue($this->storage->exists(ContentKey::page('home')));
    }

    /**
     * The point of the whole exercise: what a save replaces is still reachable
     * afterwards.
     */
    public function testOverwritingLeavesThePreviousStateRecoverable(): void
    {
        $page = Content::create(ContentKey::page('home'), ['title' => 'Original']);
        $this->storage->save($page);

        $page->update(['title' => 'Overwritten']);
        $this->storage->save($page);

        $history = $this->versions->all(ContentKey::page('home'));

        $this->assertCount(2, $history);
        $this->assertSame('Overwritten', $history[0]->content()->title());
        $this->assertSame('Original', $history[1]->content()->title());
    }

    public function testTheWriterIsRecordedAgainstTheVersion(): void
    {
        $this->author = 'grace';
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $this->assertSame('grace', $this->versions->all(ContentKey::page('home'))[0]->author);
    }

    /**
     * A write with no session attributes the change to nobody rather than
     * guessing.
     */
    public function testAWriteWithNoIdentifiableAuthorRecordsNone(): void
    {
        $storage = new VersioningStorage(new JsonStorage($this->contentDir), $this->versions);
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $this->assertNull($this->versions->all(ContentKey::page('home'))[0]->author);
    }

    /**
     * Deletion was the one operation with no way back at all.
     */
    public function testDeletingRetainsTheStateItRemoved(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Doomed']));

        $this->assertTrue($this->storage->delete(ContentKey::page('home')));
        $this->assertNull($this->storage->find(ContentKey::page('home')));

        $history = $this->versions->all(ContentKey::page('home'));

        $this->assertSame(Version::REASON_DELETE, $history[0]->reason);
        $this->assertSame('Doomed', $history[0]->content()->title());
    }

    public function testDeletingSomethingAbsentRecordsNothing(): void
    {
        $this->assertFalse($this->storage->delete(ContentKey::page('never-existed')));
        $this->assertSame([], $this->versions->all(ContentKey::page('never-existed')));
    }

    public function testAnExplicitReasonIsCarriedOntoTheVersion(): void
    {
        $this->storage->saveWithReason(
            Content::create(ContentKey::page('home'), ['title' => 'Put back']),
            Version::REASON_RESTORE
        );

        $this->assertSame(
            Version::REASON_RESTORE,
            $this->versions->all(ContentKey::page('home'))[0]->reason
        );
    }

    public function testRetentionAppliesToWritesThroughTheDecorator(): void
    {
        $storage = new VersioningStorage(
            new JsonStorage($this->contentDir),
            new JsonVersionStore($this->versionsDir, RetentionPolicy::keeping(2)),
            fn (): ?string => $this->author,
        );

        $page = Content::create(ContentKey::page('home'), ['title' => 'One']);
        foreach (['One', 'Two', 'Three', 'Four'] as $title) {
            $page->update(['title' => $title]);
            $storage->save($page);
        }

        $this->assertCount(2, $this->versions->all(ContentKey::page('home')));
    }

    /**
     * A history that quietly stops recording is worse than one that fails
     * loudly: the editor goes on trusting a safety net that is gone.
     */
    public function testAFailureToRetainIsNotSwallowed(): void
    {
        $storage = new VersioningStorage(
            new JsonStorage($this->contentDir),
            new JsonVersionStore($this->versionsDir),
        );

        $this->expectException(\InvalidArgumentException::class);
        $storage->save(Content::create(ContentKey::fromString('page:../../evil')));
    }

    public function testReadsAreLeftEntirelyToTheWrappedBackend(): void
    {
        $this->storage->save(Content::create(ContentKey::page('alpha')));
        $this->storage->save(Content::create(ContentKey::page('bravo')));

        $slugs = array_map(
            static fn (Content $c): string => $c->slug(),
            $this->storage->findByType('page')
        );

        $this->assertSame(['alpha', 'bravo'], $slugs);
    }
}
