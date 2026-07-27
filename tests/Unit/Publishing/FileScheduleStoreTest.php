<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Publishing;

use Click\Cms\Domain\Publishing\PublicationSchedule;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Publishing\FileScheduleStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FileScheduleStoreTest extends TestCase
{
    private string $root;
    private FileScheduleStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-schedule-' . bin2hex(random_bytes(6));
        $this->store = new FileScheduleStore($this->root);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->root);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /* ----------------------------------------------------- round trips -- */

    public function testAnUnscheduledDocumentHasAnEmptySchedule(): void
    {
        $this->assertTrue($this->store->find(ContentKey::page('home'))->isEmpty());
    }

    public function testItStoresAndReadsBackASchedule(): void
    {
        $key = ContentKey::page('home');
        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $this->assertEquals($this->at('2026-08-01 09:00'), $this->store->find($key)->publishAt);
    }

    /**
     * The locale is part of the key, so the German translation of a page can be
     * scheduled independently of the English one. Sharing a schedule between
     * them would make publishing a translation impossible to time separately —
     * which is the ordinary case, since translations arrive late.
     */
    public function testEachLanguageIsScheduledSeparately(): void
    {
        $this->store->save(ContentKey::page('home', 'en'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));
        $this->store->save(ContentKey::page('home', 'de'), PublicationSchedule::of($this->at('2026-09-01 09:00'), null));

        $this->assertEquals($this->at('2026-08-01 09:00'), $this->store->find(ContentKey::page('home', 'en'))->publishAt);
        $this->assertEquals($this->at('2026-09-01 09:00'), $this->store->find(ContentKey::page('home', 'de'))->publishAt);
    }

    public function testSavingAnEmptyScheduleForgetsTheDocumentEntirely(): void
    {
        $key = ContentKey::page('home');
        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $this->store->save($key, PublicationSchedule::none());

        $this->assertTrue($this->store->find($key)->isEmpty());
        $this->assertSame([], $this->store->due($this->at('2030-01-01 00:00')));
    }

    public function testClearingRemovesASchedule(): void
    {
        $key = ContentKey::page('home');
        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $this->store->clear($key);

        $this->assertTrue($this->store->find($key)->isEmpty());
    }

    public function testClearingSomethingUnscheduledIsNotAnError(): void
    {
        $this->store->clear(ContentKey::page('never-scheduled'));

        $this->assertTrue(true);
    }

    /* --------------------------------------------------- attribution -- */

    public function testItRemembersWhoScheduledSomething(): void
    {
        $key = ContentKey::page('home');
        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null), 'editor-jo');

        $this->assertSame('editor-jo', $this->store->scheduledBy($key));
        $this->assertSame('editor-jo', $this->store->due($this->at('2026-08-02 00:00'))[0]->scheduledBy);
    }

    /**
     * Moving a date is not claiming authorship of the schedule. Erasing the
     * original name would lose the one attribution the audit trail wants when
     * the sweep eventually fires.
     */
    public function testChangingATimeWithoutNamingAnAuthorKeepsTheOriginalOne(): void
    {
        $key = ContentKey::page('home');
        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null), 'editor-jo');

        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-03 09:00'), null));

        $this->assertSame('editor-jo', $this->store->scheduledBy($key));
    }

    public function testANamedAuthorReplacesTheOneOnDisk(): void
    {
        $key = ContentKey::page('home');
        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-01 09:00'), null), 'editor-jo');

        $this->store->save($key, PublicationSchedule::of($this->at('2026-08-03 09:00'), null), 'editor-sam');

        $this->assertSame('editor-sam', $this->store->scheduledBy($key));
    }

    public function testAnUnscheduledDocumentHasNoAuthor(): void
    {
        $this->assertNull($this->store->scheduledBy(ContentKey::page('never-scheduled')));
    }

    /* ------------------------------------------------------------ due -- */

    public function testDueReturnsOnlyWhatHasComeAround(): void
    {
        $this->store->save(ContentKey::page('soon'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));
        $this->store->save(ContentKey::page('later'), PublicationSchedule::of($this->at('2026-12-01 09:00'), null));

        $due = $this->store->due($this->at('2026-08-01 09:00'));

        $this->assertCount(1, $due);
        $this->assertSame('soon', $due[0]->key->slug);
    }

    public function testDueCarriesTheKeyItsLocaleAndTheSchedule(): void
    {
        $this->store->save(ContentKey::page('home', 'de'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $due = $this->store->due($this->at('2026-08-02 00:00'));

        $this->assertCount(1, $due);
        $this->assertSame('page', $due[0]->key->type);
        $this->assertSame('de', $due[0]->key->locale->code);
        $this->assertSame('home', $due[0]->key->slug);
        $this->assertEquals($this->at('2026-08-01 09:00'), $due[0]->schedule->publishAt);
    }

    public function testDueSpansMoreThanOneContentType(): void
    {
        $this->store->save(ContentKey::page('home'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));
        $this->store->save(ContentKey::for('post', 'hello'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));

        $this->assertCount(2, $this->store->due($this->at('2026-08-02 00:00')));
    }

    /**
     * The sweeper runs unattended over every schedule on the site. A file
     * holding something that is not a schedule at all must cost that one
     * document, not the run — otherwise a single corrupt write stops every
     * other page on the site from ever being published.
     */
    public function testACorruptFileDoesNotStopTheSweep(): void
    {
        $this->store->save(ContentKey::page('good'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));
        file_put_contents($this->root . '/page/en/broken.json', '{not json at all');

        $due = $this->store->due($this->at('2026-08-02 00:00'));

        $this->assertCount(1, $due);
        $this->assertSame('good', $due[0]->key->slug);
    }

    public function testSweepingAnEmptyStoreYieldsNothing(): void
    {
        $this->assertSame([], $this->store->due($this->at('2030-01-01 00:00')));
    }

    /**
     * A key that could escape the schedule directory is refused on the way in,
     * for the same reason `ContentKeyRules` refuses it for content: type and
     * slug become path segments here too.
     */
    public function testAnUnsafeKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->save(ContentKey::fromString('page:../../etc/passwd'), PublicationSchedule::of($this->at('2026-08-01 09:00'), null));
    }
}
