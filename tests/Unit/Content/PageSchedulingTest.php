<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Publishing\FileScheduleStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * Scheduling as an editor reaches it: through the page service, with the same
 * permission rules that govern publishing by hand.
 *
 * The rule being pinned down is that scheduling is publishing, deferred. An
 * account that may not publish a page may not arrange for it to publish itself
 * at three in the morning either — otherwise the role model describes nothing,
 * which is the same defect draft-and-publish was introduced to fix.
 */
final class PageSchedulingTest extends TestCase
{
    private string $root;
    private ContentService $content;
    private PageService $pages;
    private FileScheduleStore $schedules;

    private const EDITOR = ['username' => 'ed', 'role' => 'editor'];
    private const AUTHOR = ['username' => 'al', 'role' => 'author'];
    private const VIEWER = ['username' => 'vi', 'role' => 'viewer'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-scheduling-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/content', 0o775, true);

        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->root . '/content'),
            new JsonVersionStore($this->root . '/versions'),
        ));

        $this->schedules = new FileScheduleStore($this->root . '/schedule');

        $this->pages = new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'),
            schedules: $this->schedules,
        );

        $this->content->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
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

    /* ----------------------------------------------------------- happy -- */

    public function testAnEditorCanScheduleAPublication(): void
    {
        $result = $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('2026-08-01T09:00:00+00:00', $result['schedule']['publishAt']);
    }

    public function testTheScheduleIsReadBack(): void
    {
        $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $result = $this->pages->scheduleOf('home', self::EDITOR);

        $this->assertSame('2026-08-01T09:00:00+00:00', $result['schedule']['publishAt']);
        $this->assertSame('ed', $result['schedule']['scheduledBy']);
    }

    public function testAScheduleCanBeCleared(): void
    {
        $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $result = $this->pages->clearSchedule('home', self::EDITOR);

        $this->assertNull($result['error']);
        $this->assertNull($this->pages->scheduleOf('home', self::EDITOR)['schedule']['publishAt']);
    }

    public function testATakedownCanBeScheduledOnItsOwn(): void
    {
        $result = $this->pages->schedule('home', self::EDITOR, null, '2026-09-01T09:00:00+00:00');

        $this->assertNull($result['error']);
        $this->assertSame('2026-09-01T09:00:00+00:00', $result['schedule']['unpublishAt']);
    }

    /**
     * The locale is part of the key, so a German translation can be timed
     * separately from the English original — which is the ordinary case, because
     * translations arrive after the page they translate.
     */
    public function testEachLanguageIsScheduledSeparately(): void
    {
        $this->content->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));

        $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null, 'en');
        $this->pages->schedule('home', self::EDITOR, '2026-09-01T09:00:00+00:00', null, 'de');

        $this->assertSame(
            '2026-09-01T09:00:00+00:00',
            $this->pages->scheduleOf('home', self::EDITOR, 'de')['schedule']['publishAt']
        );
    }

    /* ----------------------------------------------------- permissions -- */

    /**
     * The rule this whole feature stands on. An author cannot publish, so an
     * author cannot schedule a publication either — a deferred publish that
     * skipped the permission check would be a way for any author to put
     * anything live, just more slowly.
     */
    public function testAnAuthorCannotScheduleAPublication(): void
    {
        $result = $this->pages->schedule('home', self::AUTHOR, '2026-08-01T09:00:00+00:00', null);

        $this->assertSame(403, $result['status']);
        $this->assertTrue($this->schedules->find(ContentKey::page('home'))->isEmpty());
    }

    public function testAViewerCannotScheduleAnything(): void
    {
        $result = $this->pages->schedule('home', self::VIEWER, '2026-08-01T09:00:00+00:00', null);

        $this->assertSame(403, $result['status']);
    }

    public function testAnAuthorCannotClearSomebodyElsesSchedule(): void
    {
        $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $result = $this->pages->clearSchedule('home', self::AUTHOR);

        $this->assertSame(403, $result['status']);
        $this->assertFalse($this->schedules->find(ContentKey::page('home'))->isEmpty());
    }

    /* -------------------------------------------------------- refusals -- */

    public function testSchedulingAPageThatDoesNotExistIsRefused(): void
    {
        $result = $this->pages->schedule('nowhere', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $this->assertSame(404, $result['status']);
    }

    /**
     * Refused with the reason, at the boundary, while the editor is still
     * looking at the form — rather than accepted and silently dropped by the
     * sweeper hours later.
     */
    public function testATakedownBeforeItsPublicationIsRefused(): void
    {
        $result = $this->pages->schedule(
            'home',
            self::EDITOR,
            '2026-09-01T09:00:00+00:00',
            '2026-08-01T09:00:00+00:00'
        );

        $this->assertSame(422, $result['status']);
        $this->assertNotNull($result['error']);
    }

    public function testATimeThatIsNotATimeIsRefused(): void
    {
        $result = $this->pages->schedule('home', self::EDITOR, 'next tuesday-ish', null);

        $this->assertSame(422, $result['status']);
    }

    /**
     * A relative expression would mean something different on every sweep, so
     * it is not a schedule at all. Refused rather than resolved once and stored,
     * because "+1 week" resolved at save time is not what the editor typed.
     */
    public function testARelativeTimeIsRefused(): void
    {
        $result = $this->pages->schedule('home', self::EDITOR, '+1 week', null);

        $this->assertSame(422, $result['status']);
    }

    public function testClearingBothEndsIsTheSameAsClearing(): void
    {
        $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $result = $this->pages->schedule('home', self::EDITOR, null, null);

        $this->assertNull($result['error']);
        $this->assertTrue($this->schedules->find(ContentKey::page('home'))->isEmpty());
    }

    /* --------------------------------------------------------- pending -- */

    public function testPendingListsWhatIsScheduled(): void
    {
        $this->content->save(Content::create(ContentKey::page('offer'), ['title' => 'Offer']));
        $this->pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);
        $this->pages->schedule('offer', self::EDITOR, null, '2026-09-01T09:00:00+00:00');

        $pending = $this->pages->pendingSchedules(self::EDITOR);

        $this->assertCount(2, $pending['schedules']);
    }

    public function testPendingIsOrderedByWhatHappensNext(): void
    {
        $this->content->save(Content::create(ContentKey::page('offer'), ['title' => 'Offer']));
        $this->pages->schedule('home', self::EDITOR, '2026-12-01T09:00:00+00:00', null);
        $this->pages->schedule('offer', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $pending = $this->pages->pendingSchedules(self::EDITOR);

        $this->assertSame('offer', $pending['schedules'][0]['slug']);
        $this->assertSame('home', $pending['schedules'][1]['slug']);
    }

    public function testAViewerCannotListPendingSchedules(): void
    {
        $this->assertSame(403, $this->pages->pendingSchedules(self::VIEWER)['status']);
    }

    /* ---------------------------------------------------- without a store -- */

    /**
     * A page service built with no schedule store — which is what an
     * installation that never configured one has — refuses rather than
     * pretending to have scheduled something. Silent degradation is the
     * recurring bug `core.md` names.
     */
    public function testWithoutAStoreSchedulingIsRefusedRatherThanIgnored(): void
    {
        $pages = new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );

        $result = $pages->schedule('home', self::EDITOR, '2026-08-01T09:00:00+00:00', null);

        $this->assertSame(501, $result['status']);
    }
}
