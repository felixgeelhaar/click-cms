<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Audit\AuditAction;
use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Audit\AuditingStorage;
use Click\Cms\Infrastructure\Audit\JsonAuditLog;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

final class AuditingStorageTest extends TestCase
{
    private string $root;
    private JsonAuditLog $log;
    private VersioningStorage $inner;
    private AuditingStorage $storage;
    private ?string $author = 'ada';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-auditing-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/content', 0o775, true);

        $this->log = new JsonAuditLog($this->root . '/audit');
        $this->inner = new VersioningStorage(
            new JsonStorage($this->root . '/content'),
            new JsonVersionStore($this->root . '/versions'),
            fn (): ?string => $this->author,
        );
        $this->storage = new AuditingStorage(
            $this->inner,
            $this->log,
            fn (): ?string => $this->author,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
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

    private function page(string $slug, string $title): Content
    {
        return Content::create(ContentKey::page($slug), ['title' => $title, 'owner' => 'ada']);
    }

    /**
     * The two halves of the bargain: the audit entry is written AND the save is
     * still delegated. A decorator that recorded but did not persist, or
     * persisted but did not record, would each be a different kind of lie.
     */
    public function testSaveRecordsAnEntryAndDelegatesTheSave(): void
    {
        $this->storage->save($this->page('home', 'Home'));

        // Delegated: the working copy reached the inner store.
        $this->assertSame('Home', $this->inner->draft(ContentKey::page('home'))?->title());

        // Recorded: exactly one entry, naming who/what/which.
        $recent = $this->log->recent(10);
        $this->assertCount(1, $recent);
        $this->assertSame('ada', $recent[0]->actor);
        $this->assertSame(AuditAction::Created, $recent[0]->action);
        $this->assertSame('page:en:home', $recent[0]->document);
    }

    /**
     * A first write is a creation; a later write to the same document is an
     * edit. Getting this wrong turns the trail into noise — every save reading
     * as a brand-new page.
     */
    public function testASecondSaveIsRecordedAsAnUpdate(): void
    {
        $this->storage->save($this->page('home', 'Home'));
        $this->storage->save($this->page('home', 'Home revised'));

        $recent = $this->log->recent(10);
        $this->assertCount(2, $recent);
        $this->assertSame(AuditAction::Updated, $recent[0]->action);
        $this->assertSame(AuditAction::Created, $recent[1]->action);
    }

    public function testDeleteRecordsAndDelegates(): void
    {
        $this->storage->save($this->page('home', 'Home'));

        $removed = $this->storage->delete(ContentKey::page('home'));

        $this->assertTrue($removed);
        $this->assertNull($this->inner->draft(ContentKey::page('home')));

        $recent = $this->log->recent(10);
        $this->assertSame(AuditAction::Deleted, $recent[0]->action);
        $this->assertSame('page:en:home', $recent[0]->document);
    }

    /**
     * Deleting something that was never there changed nothing, so there is
     * nothing to attribute. Recording a delete that removed no document would
     * put a phantom event in the trail.
     */
    public function testDeletingWhatNeverExistedRecordsNothing(): void
    {
        $removed = $this->storage->delete(ContentKey::page('ghost'));

        $this->assertFalse($removed);
        $this->assertSame([], $this->log->recent(10));
    }

    public function testPublishRecordsAndDelegates(): void
    {
        $this->storage->save($this->page('home', 'Home'));

        $live = $this->storage->publish(ContentKey::page('home'));

        $this->assertSame('Home', $live?->title());
        // Live document reached content/.
        $this->assertSame('Home', $this->inner->find(ContentKey::page('home'))?->title());

        $recent = $this->log->recent(10);
        $this->assertSame(AuditAction::Published, $recent[0]->action);
    }

    public function testPublishingNothingRecordsNothing(): void
    {
        $live = $this->storage->publish(ContentKey::page('ghost'));

        $this->assertNull($live);
        $this->assertSame([], array_filter(
            $this->log->recent(10),
            static fn (AuditEntry $e): bool => $e->action === AuditAction::Published,
        ));
    }

    public function testUnpublishRecordsAndDelegates(): void
    {
        $this->storage->save($this->page('home', 'Home'));
        $this->storage->publish(ContentKey::page('home'));

        $taken = $this->storage->unpublish(ContentKey::page('home'));

        $this->assertTrue($taken);
        $this->assertNull($this->inner->find(ContentKey::page('home')));

        $recent = $this->log->recent(10);
        $this->assertSame(AuditAction::Unpublished, $recent[0]->action);
    }

    /**
     * A restore is a save the caller labels as one, and the audit trail must
     * carry that label: "somebody put an earlier version back" is a materially
     * different event from an ordinary edit, and the whole reason restore is
     * held to its own capability.
     */
    public function testSaveWithRestoreReasonIsRecordedAsARestore(): void
    {
        $this->storage->save($this->page('home', 'Home'));
        $this->storage->saveWithReason($this->page('home', 'Home restored'), Version::REASON_RESTORE);

        $recent = $this->log->recent(10);
        $this->assertSame(AuditAction::Restored, $recent[0]->action);
    }

    /**
     * The author is read at the moment of the write, not when the decorator was
     * built — a storage backend is constructed once at boot and serves many
     * requests, so who is writing is not knowable at construction.
     */
    public function testTheAuthorIsResolvedLazilyPerWrite(): void
    {
        $this->author = 'ada';
        $this->storage->save($this->page('home', 'Home'));

        $this->author = 'bob';
        $this->storage->save($this->page('about', 'About'));

        $byDocument = [];
        foreach ($this->log->recent(10) as $entry) {
            $byDocument[$entry->document] = $entry->actor;
        }

        $this->assertSame('ada', $byDocument['page:en:home']);
        $this->assertSame('bob', $byDocument['page:en:about']);
    }

    /**
     * A write from the CLI or a plugin with no session records no actor, rather
     * than being pinned on whoever the resolver last happened to name.
     */
    public function testAWriteWithNoResolvedAuthorRecordsNoActor(): void
    {
        $this->author = null;
        $this->storage->save($this->page('home', 'Home'));

        $this->assertNull($this->log->recent(10)[0]->actor);
    }

    /**
     * Reads are not events. Auditing them would bury the writes that matter
     * under a flood of every page view, and the trail exists to answer "who
     * changed this", not "who looked at it".
     */
    public function testReadsAreNotAudited(): void
    {
        $this->storage->save($this->page('home', 'Home'));
        $before = $this->log->recent(50);

        $this->storage->find(ContentKey::page('home'));
        $this->storage->findByType('page');
        $this->storage->exists(ContentKey::page('home'));
        $this->storage->draft(ContentKey::page('home'));
        $this->storage->workingCopies('page');
        $this->storage->publicationOf(ContentKey::page('home'));

        $this->assertCount(count($before), $this->log->recent(50));
    }

    /**
     * Reads still reach the inner store unchanged: the decorator adds recording
     * to writes and must be transparent to everything else.
     */
    public function testReadsDelegateUnchanged(): void
    {
        $this->storage->save($this->page('home', 'Home'));

        $this->assertSame('Home', $this->storage->draft(ContentKey::page('home'))?->title());
        $this->assertTrue($this->storage->exists(ContentKey::page('home')) === $this->inner->exists(ContentKey::page('home')));
        $this->assertCount(1, $this->storage->workingCopies('page'));
    }
}
