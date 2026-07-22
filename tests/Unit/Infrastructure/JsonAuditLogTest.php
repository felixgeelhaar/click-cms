<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Audit\AuditAction;
use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Audit\JsonAuditLog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class JsonAuditLogTest extends TestCase
{
    private string $dir;
    private JsonAuditLog $log;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-audit-' . bin2hex(random_bytes(6));
        $this->log = new JsonAuditLog($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
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

    private function entry(
        string $actor,
        AuditAction $action,
        ContentKey $document,
        string $at,
    ): AuditEntry {
        return AuditEntry::of($actor, $action, $document, new DateTimeImmutable($at));
    }

    public function testAppendThenReadRoundTrips(): void
    {
        $this->log->append(
            $this->entry('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00')
        );

        $recent = $this->log->recent(10);

        $this->assertCount(1, $recent);
        $this->assertSame('ada', $recent[0]->actor);
        $this->assertSame(AuditAction::Created, $recent[0]->action);
        $this->assertSame('page:en:home', $recent[0]->document);
    }

    /**
     * The property that makes an audit trail one: a second write must not lose
     * the first. An audit you can silently overwrite is not audit. This is the
     * regression that the whole file-format choice exists to guarantee.
     */
    public function testAppendOnlyASecondWriteDoesNotLoseTheFirst(): void
    {
        $this->log->append(
            $this->entry('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00')
        );
        $this->log->append(
            $this->entry('bob', AuditAction::Updated, ContentKey::page('home'), '2026-07-22T11:00:00+00:00')
        );

        $recent = $this->log->recent(10);

        $this->assertCount(2, $recent);
        $actors = array_map(static fn (AuditEntry $e): string => (string) $e->actor, $recent);
        $this->assertContains('ada', $actors);
        $this->assertContains('bob', $actors);
    }

    /**
     * Newest first: an operator reading an audit trail wants the most recent
     * events, and the trail is appended in the order things happened.
     */
    public function testRecentReturnsNewestFirst(): void
    {
        $this->log->append($this->entry('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00'));
        $this->log->append($this->entry('bob', AuditAction::Updated, ContentKey::page('home'), '2026-07-22T11:00:00+00:00'));
        $this->log->append($this->entry('cy', AuditAction::Published, ContentKey::page('home'), '2026-07-22T12:00:00+00:00'));

        $recent = $this->log->recent(10);

        $this->assertSame(['cy', 'bob', 'ada'], array_map(static fn (AuditEntry $e): ?string => $e->actor, $recent));
    }

    public function testRecentIsCapped(): void
    {
        foreach (range(1, 5) as $n) {
            $this->log->append(
                $this->entry("user{$n}", AuditAction::Updated, ContentKey::page('home'), "2026-07-22T10:0{$n}:00+00:00")
            );
        }

        $this->assertCount(2, $this->log->recent(2));
    }

    /**
     * Filtering by document: an editor asks "who has touched this page", and
     * the answer must not be polluted by every other page's history.
     */
    public function testForDocumentReturnsOnlyThatDocumentNewestFirst(): void
    {
        $this->log->append($this->entry('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00'));
        $this->log->append($this->entry('bob', AuditAction::Created, ContentKey::page('about'), '2026-07-22T10:30:00+00:00'));
        $this->log->append($this->entry('cy', AuditAction::Updated, ContentKey::page('home'), '2026-07-22T11:00:00+00:00'));

        $forHome = $this->log->forDocument(ContentKey::page('home'), 10);

        $this->assertSame(['cy', 'ada'], array_map(static fn (AuditEntry $e): ?string => $e->actor, $forHome));
    }

    /**
     * Two documents whose slug matches but whose language does not are two
     * documents, exactly as they are everywhere else in the system.
     */
    public function testForDocumentIsPerLanguage(): void
    {
        $this->log->append($this->entry('ada', AuditAction::Updated, ContentKey::page('home', 'en'), '2026-07-22T10:00:00+00:00'));
        $this->log->append($this->entry('bob', AuditAction::Updated, ContentKey::page('home', 'de'), '2026-07-22T10:30:00+00:00'));

        $en = $this->log->forDocument(ContentKey::page('home', 'en'), 10);

        $this->assertCount(1, $en);
        $this->assertSame('ada', $en[0]->actor);
    }

    public function testRecentIsEmptyBeforeAnythingIsRecorded(): void
    {
        $this->assertSame([], $this->log->recent(10));
        $this->assertSame([], $this->log->forDocument(ContentKey::page('home'), 10));
    }

    /**
     * One damaged line must not hide the rest, for the same reason the version
     * store skips an unreadable snapshot: a trail that shows what it can still
     * read beats one that shows an error because a single record is corrupt.
     */
    public function testOneCorruptLineDoesNotHideTheOthers(): void
    {
        $this->log->append($this->entry('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00'));
        $this->log->append($this->entry('bob', AuditAction::Updated, ContentKey::page('home'), '2026-07-22T11:00:00+00:00'));

        // Corrupt the first line in place, leaving the second intact.
        $file = $this->dir . '/audit.log';
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $lines[0] = '{ not json';
        file_put_contents($file, implode("\n", $lines) . "\n");

        $recent = $this->log->recent(10);

        $this->assertCount(1, $recent);
        $this->assertSame('bob', $recent[0]->actor);
    }

    public function testAppendLeavesNoTemporaryFilesBehind(): void
    {
        $this->log->append($this->entry('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00'));

        $this->assertSame([], glob($this->dir . '/*.tmp') ?: []);
    }
}
