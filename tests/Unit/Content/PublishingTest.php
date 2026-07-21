<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * Draft-and-publish through the service an editor's requests actually reach.
 *
 * The single sentence being pinned down: editing a live page does not change
 * the live page. Everything else here is a consequence of it, or a permission
 * question that only becomes answerable once it is true — an author who "cannot
 * publish" could previously put anything in front of every visitor by pressing
 * Save, which made the role model a description of nothing.
 */
final class PublishingTest extends TestCase
{
    private string $root;
    private ContentService $content;
    private PageService $pages;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-publishing-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/content', 0o775, true);

        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->root . '/content'),
            new JsonVersionStore($this->root . '/versions'),
        ));

        $this->pages = new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
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

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return ['username' => 'boss', 'role' => 'admin'];
    }

    /** @return array<string, mixed> */
    private function author(string $username = 'ada'): array
    {
        return ['username' => $username, 'role' => 'author'];
    }

    /** What a visitor with no account would be served. */
    private function public(string $slug): ?string
    {
        return $this->content->page($slug)?->title();
    }

    /* ------------------------------------------------------ the core rule -- */

    public function testANewPageIsNotPublicUntilItIsPublished(): void
    {
        $this->pages->create(['title' => 'Unannounced', 'slug' => 'news'], $this->admin());

        $this->assertNull($this->public('news'));
        // The editor can still reach their own work.
        $this->assertSame('Unannounced', $this->pages->find('news')?->title());

        $this->pages->publish('news', $this->admin());

        $this->assertSame('Unannounced', $this->public('news'));
    }

    /**
     * The failure the whole change exists to prevent: an editor opening a live
     * page, typing half a sentence and having every visitor read it.
     */
    public function testEditingAPublishedPageDoesNotChangeWhatVisitorsRead(): void
    {
        $this->pages->create(['title' => 'Live text', 'slug' => 'home'], $this->admin());
        $this->pages->publish('home', $this->admin());

        $this->pages->update('home', ['title' => 'Half-written replaceme'], $this->admin());

        $this->assertSame('Live text', $this->public('home'));
        $this->assertSame('Half-written replaceme', $this->pages->find('home')?->title());

        $state = $this->pages->publicationOf('home');
        $this->assertTrue($state->published);
        $this->assertTrue($state->hasUnpublishedChanges);

        $this->pages->publish('home', $this->admin());

        $this->assertSame('Half-written replaceme', $this->public('home'));
        $this->assertFalse($this->pages->publicationOf('home')->hasUnpublishedChanges);
    }

    public function testUnpublishingTakesThePageDownButKeepsTheWork(): void
    {
        $this->pages->create(['title' => 'Seasonal', 'slug' => 'offer'], $this->admin());
        $this->pages->publish('offer', $this->admin());

        $result = $this->pages->unpublish('offer', $this->admin());

        $this->assertNull($result['error']);
        $this->assertNull($this->public('offer'));
        $this->assertSame('Seasonal', $this->pages->find('offer')?->title());

        $state = $this->pages->publicationOf('offer');
        $this->assertFalse($state->published);
        // Taken down reads differently from never having been up.
        $this->assertFalse($state->neverPublished);
    }

    /**
     * Refused rather than reported as a success: an editor told "unpublished"
     * about a page that was never live will not go looking for the reason their
     * change is still not visible.
     */
    public function testUnpublishingSomethingThatWasNeverLiveIsRefused(): void
    {
        $this->pages->create(['title' => 'Draft', 'slug' => 'draft'], $this->admin());

        $this->assertSame(409, $this->pages->unpublish('draft', $this->admin())['status']);
        $this->assertSame(404, $this->pages->unpublish('ghost', $this->admin())['status']);
    }

    /* ---------------------------------------------------------- languages -- */

    /**
     * Publication is per language, because `page:de:home` and `page:en:home`
     * are two documents and always have been. Publishing a translation the
     * moment its original is approved is exactly the accident this avoids.
     */
    public function testPublishingOneLanguageLeavesTheOtherAlone(): void
    {
        $this->pages->create(['title' => 'Home', 'slug' => 'home'], $this->admin(), 'en');
        $this->pages->create(['title' => 'Startseite', 'slug' => 'home'], $this->admin(), 'de');

        $this->pages->publish('home', $this->admin(), 'en');

        $this->assertSame('Home', $this->content->page('home', 'en')?->title());
        $this->assertNull($this->content->page('home', 'de'));

        $this->assertTrue($this->pages->publicationOf('home', 'en')->published);
        $this->assertTrue($this->pages->publicationOf('home', 'de')->neverPublished);
    }

    /* -------------------------------------------------------- permissions -- */

    /**
     * The sentence the role comments have claimed since they were written, and
     * which only becomes enforceable now that saving is not publishing.
     */
    public function testAnAuthorCannotPublishEvenTheirOwnPage(): void
    {
        $this->pages->create(['title' => 'Mine', 'slug' => 'mine'], $this->author());

        $result = $this->pages->publish('mine', $this->author());

        $this->assertSame(403, $result['status']);
        $this->assertSame('You do not have permission to publish this page.', $result['error']);
        $this->assertNull($this->public('mine'));

        // Nor can they take one down.
        $this->pages->publish('mine', $this->admin());
        $this->assertSame(403, $this->pages->unpublish('mine', $this->author())['status']);
        $this->assertSame('Mine', $this->public('mine'));
    }

    public function testAnAuthorCanStillWriteAndEditTheirOwnDraft(): void
    {
        $this->assertSame(
            201,
            $this->pages->create(['title' => 'Mine', 'slug' => 'mine'], $this->author())['status']
        );
        $this->assertSame(
            200,
            $this->pages->update('mine', ['title' => 'Mine, revised'], $this->author())['status']
        );
    }

    /**
     * A viewer is where an unrecognised role lands, so it must be safe to be
     * there by accident.
     */
    public function testAViewerCannotPublish(): void
    {
        $this->pages->create(['title' => 'Theirs', 'slug' => 'theirs'], $this->admin());

        foreach ([['role' => 'viewer'], ['role' => 'ghost']] as $user) {
            $this->assertSame(403, $this->pages->publish('theirs', $user + ['username' => 'val'])['status']);
        }

        $this->assertNull($this->public('theirs'));
    }

    /* ----------------------------------------------------------- listings -- */

    /**
     * An editor's listing has to include work nobody has published, or the
     * first thing they cannot find is the page they wrote this morning. A
     * reader's must not.
     */
    public function testAnEditorsListingIncludesDraftsAndAReadersDoesNot(): void
    {
        $this->pages->create(['title' => 'Live', 'slug' => 'live'], $this->admin());
        $this->pages->publish('live', $this->admin());
        $this->pages->create(['title' => 'Pending', 'slug' => 'pending'], $this->admin());

        $editors = array_map(static fn ($p): string => $p->slug(), $this->pages->all());
        sort($editors);

        $this->assertSame(['live', 'pending'], $editors);
        $this->assertSame(
            ['live'],
            array_map(static fn ($p): string => $p->slug(), $this->pages->published())
        );
    }

    /**
     * Deleting an unpublished page must actually remove it from the editor's
     * view. It is retained in history, which is where it is recovered from —
     * not somewhere it goes on quietly appearing in listings.
     */
    public function testDeletingADraftRemovesItFromTheEditorsView(): void
    {
        $this->pages->create(['title' => 'Mistake', 'slug' => 'mistake'], $this->admin());

        $this->assertNull($this->pages->delete('mistake', $this->admin())['error']);
        $this->assertNull($this->pages->find('mistake'));
        $this->assertSame([], $this->pages->all());
    }

    /* --------------------------------------------------- publishable types -- */

    /**
     * Which types are publishable is a stated list rather than something
     * inferred from a payload, so it cannot be got wrong by sending the wrong
     * body — and so it is testable at all.
     */
    public function testOnlyPagesArePublishable(): void
    {
        $this->assertTrue(Publishable::includes('page'));
        $this->assertFalse(Publishable::includes('user'));
        $this->assertFalse(Publishable::includes('media'));
        $this->assertSame(['page'], Publishable::types());
    }

    /**
     * Nobody drafts a login. An account that existed only as a version would
     * make signing in depend on somebody having pressed Publish, which is why
     * unpublishable types still write straight through.
     */
    public function testAnAccountIsReadableTheMomentItIsSaved(): void
    {
        $this->content->save(
            \Click\Cms\Domain\Content\Content::create(ContentKey::user('admin'), ['role' => 'admin'])
        );

        $this->assertSame('admin', $this->content->user('admin')?->data['role']);
    }
}
