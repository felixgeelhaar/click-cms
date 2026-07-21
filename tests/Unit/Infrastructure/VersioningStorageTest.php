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

    /**
     * Rewritten from "a save is still a save", which asserted the old model:
     * that saving a page put it straight into `content/` and therefore straight
     * in front of the public. That is the behaviour draft-and-publish removes,
     * so the test now pins down its replacement — the save is kept, and it is
     * kept somewhere the public read path does not look.
     */
    public function testSavingAPageRecordsItWithoutPublishingIt(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $this->assertNull($this->storage->find(ContentKey::page('home')));
        $this->assertFalse($this->storage->exists(ContentKey::page('home')));

        // Not lost, just not live.
        $this->assertSame('Home', $this->storage->draft(ContentKey::page('home'))?->title());
    }

    public function testPublishingPromotesTheWorkingCopy(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));

        $this->assertSame('Home', $this->storage->publish(ContentKey::page('home'))?->title());
        $this->assertSame('Home', $this->storage->find(ContentKey::page('home'))?->title());
        $this->assertTrue($this->storage->exists(ContentKey::page('home')));
    }

    /**
     * The sentence the whole feature exists for: editing a live page does not
     * change the live page.
     */
    public function testAnEditAfterPublishingLeavesTheLiveDocumentAlone(): void
    {
        $page = Content::create(ContentKey::page('home'), ['title' => 'Live']);
        $this->storage->save($page);
        $this->storage->publish(ContentKey::page('home'));

        $page->update(['title' => 'Being rewritten']);
        $this->storage->save($page);

        $this->assertSame('Live', $this->storage->find(ContentKey::page('home'))?->title());
        $this->assertSame('Being rewritten', $this->storage->draft(ContentKey::page('home'))?->title());

        // And publishing again is what closes the gap.
        $this->storage->publish(ContentKey::page('home'));
        $this->assertSame('Being rewritten', $this->storage->find(ContentKey::page('home'))?->title());
    }

    public function testUnpublishingRemovesTheLiveDocumentAndKeepsTheHistory(): void
    {
        $this->storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
        $this->storage->publish(ContentKey::page('home'));

        $this->assertTrue($this->storage->unpublish(ContentKey::page('home')));

        $this->assertNull($this->storage->find(ContentKey::page('home')));
        $this->assertSame('Home', $this->storage->draft(ContentKey::page('home'))?->title());
        $this->assertNotSame([], $this->versions->all(ContentKey::page('home')));
    }

    /**
     * Taking a page down must not rewind the replacement being written for it.
     * That is why unpublishing records no version: the newest version is the
     * working copy, and appending the state being removed would overwrite it.
     */
    public function testUnpublishingDoesNotRewindTheWorkingCopy(): void
    {
        $page = Content::create(ContentKey::page('home'), ['title' => 'Old']);
        $this->storage->save($page);
        $this->storage->publish(ContentKey::page('home'));

        $page->update(['title' => 'New draft']);
        $this->storage->save($page);

        $this->storage->unpublish(ContentKey::page('home'));

        $this->assertSame('New draft', $this->storage->draft(ContentKey::page('home'))?->title());
    }

    public function testPublishingSomethingThatWasNeverSavedDoesNothing(): void
    {
        $this->assertNull($this->storage->publish(ContentKey::page('never-written')));
        $this->assertFalse($this->storage->unpublish(ContentKey::page('never-written')));
    }

    /**
     * Accounts and media are not publishable — nobody drafts a login — so they
     * must still be written straight through. A user record that existed only
     * as a version would make signing in depend on somebody pressing Publish.
     */
    public function testAnUnpublishableTypeIsStillWrittenStraightThrough(): void
    {
        $this->storage->save(Content::create(ContentKey::user('admin'), ['role' => 'admin']));

        $this->assertSame('admin', $this->storage->find(ContentKey::user('admin'))?->data['role']);
        // And still versioned, which is what it always was.
        $this->assertCount(1, $this->versions->all(ContentKey::user('admin')));
    }

    /**
     * Where a document stands, derived rather than stored — see
     * {@see \Click\Cms\Domain\Publishing\PublicationState}.
     */
    public function testPublicationStateTracksTheThreeCasesAUiAsksAbout(): void
    {
        $key = ContentKey::page('home');
        $page = Content::create($key, ['title' => 'Draft']);

        $this->storage->save($page);
        $state = $this->storage->publicationOf($key);
        $this->assertFalse($state->published);
        $this->assertTrue($state->neverPublished);

        $this->storage->publish($key);
        $state = $this->storage->publicationOf($key);
        $this->assertTrue($state->published);
        $this->assertFalse($state->hasUnpublishedChanges);
        $this->assertFalse($state->neverPublished);

        $page->update(['title' => 'Edited']);
        $this->storage->save($page);
        $state = $this->storage->publicationOf($key);
        $this->assertTrue($state->published);
        $this->assertTrue($state->hasUnpublishedChanges);

        $this->storage->unpublish($key);
        $state = $this->storage->publicationOf($key);
        $this->assertFalse($state->published);
        // Taken down is not the same as never having been up, and a listing
        // reads differently for each.
        $this->assertFalse($state->neverPublished);
    }

    /**
     * Content seeded straight onto disk has no version chain, and reporting it
     * as an unpublished draft would put a "publish me" badge on every page of a
     * fresh install.
     */
    public function testSeededContentWithNoHistoryReadsAsPublishedAndClean(): void
    {
        (new JsonStorage($this->contentDir))->save(
            Content::create(ContentKey::page('seeded'), ['title' => 'Seeded'])
        );

        $state = $this->storage->publicationOf(ContentKey::page('seeded'));

        $this->assertTrue($state->published);
        $this->assertFalse($state->hasUnpublishedChanges);
        $this->assertFalse($state->neverPublished);
        $this->assertSame('Seeded', $this->storage->draft(ContentKey::page('seeded'))?->title());
    }

    /**
     * A management listing has to show work nobody has published, because
     * otherwise the first thing an editor cannot find is the page they created
     * this morning.
     */
    public function testWorkingCopiesIncludeDraftsAndLivePagesExactlyOnce(): void
    {
        $this->storage->save(Content::create(ContentKey::page('published-one'), ['title' => 'Live']));
        $this->storage->publish(ContentKey::page('published-one'));
        $this->storage->save(Content::create(ContentKey::page('draft-only'), ['title' => 'Draft']));
        (new JsonStorage($this->contentDir))->save(
            Content::create(ContentKey::page('seeded'), ['title' => 'Seeded'])
        );

        $slugs = array_map(
            static fn (Content $c): string => $c->slug(),
            $this->storage->workingCopies('page')
        );
        sort($slugs);

        $this->assertSame(['draft-only', 'published-one', 'seeded'], $slugs);
        // The reader's list is the live one, and does not include the draft.
        $this->assertSame(
            ['published-one', 'seeded'],
            array_map(
                static fn (Content $c): string => $c->slug(),
                $this->storage->findByType('page')
            )
        );
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

    /**
     * Unchanged in intent; the two pages now have to be published before the
     * reader's list can be expected to hold them, because a save no longer puts
     * anything in `content/`.
     */
    public function testReadsAreLeftEntirelyToTheWrappedBackend(): void
    {
        $this->storage->save(Content::create(ContentKey::page('alpha')));
        $this->storage->save(Content::create(ContentKey::page('bravo')));
        $this->storage->publish(ContentKey::page('alpha'));
        $this->storage->publish(ContentKey::page('bravo'));

        $slugs = array_map(
            static fn (Content $c): string => $c->slug(),
            $this->storage->findByType('page')
        );

        $this->assertSame(['alpha', 'bravo'], $slugs);
    }
}
