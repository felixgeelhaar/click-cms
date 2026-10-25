<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Translation;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Translation\TranslationFreshnessService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\ContentRevision;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Translation\JsonTranslationReviewStore;
use PHPUnit\Framework\TestCase;

final class TranslationFreshnessServiceTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private TranslationFreshnessService $freshness;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-translation-freshness-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);
        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->freshness = new TranslationFreshnessService(
            $this->content,
            new JsonTranslationReviewStore($this->base . '/data/translation-reviews'),
        );
        $this->savePage('home', 'en', 'Source');
        $this->savePage('home', 'de', 'Ziel');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function savePage(string $slug, string $locale, string $title): Content
    {
        $page = Content::create(ContentKey::page($slug, $locale), ['title' => $title, 'owner' => 'alice']);
        $this->content->save($page);
        return $page;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    public function testNoReviewRecordIsUnknownRatherThanAssumedFresh(): void
    {
        $status = $this->freshness->status('home', 'en', 'de');

        self::assertSame('unknown', $status['data']['freshness']);
        self::assertNull($status['data']['reviewedAt']);
        self::assertSame(ContentRevision::of($this->content->draftPage('home', 'en')), $status['data']['currentSourceRevision']);
    }

    public function testReviewPinsBothSavedRevisionsAndBecomesStaleWhenSourceChanges(): void
    {
        $source = $this->content->draftPage('home', 'en');
        $target = $this->content->draftPage('home', 'de');
        $marked = $this->freshness->mark('home', 'en', 'de', ContentRevision::of($source), ContentRevision::of($target), 'alice');
        self::assertSame('reviewed', $marked['data']['freshness']);
        self::assertSame(ContentRevision::of($source), $marked['data']['sourceRevision']);
        self::assertSame('alice', $marked['data']['reviewedBy']);

        $this->savePage('home', 'en', 'Changed source');
        $stale = $this->freshness->status('home', 'en', 'de');

        self::assertSame('stale', $stale['data']['freshness']);
        self::assertContains('source_changed', $stale['data']['staleReasons']);
    }

    public function testTargetChangesAfterReviewAlsoMakesTheSavedComparisonStale(): void
    {
        $source = $this->content->draftPage('home', 'en');
        $target = $this->content->draftPage('home', 'de');
        $this->freshness->mark('home', 'en', 'de', ContentRevision::of($source), ContentRevision::of($target), 'alice');

        $this->savePage('home', 'de', 'Changed target');

        self::assertContains('target_changed', $this->freshness->status('home', 'en', 'de')['data']['staleReasons']);
    }

    public function testMarkRejectsChangedSnapshotsAndDoesNotCreateARecord(): void
    {
        $source = $this->content->draftPage('home', 'en');
        $target = $this->content->draftPage('home', 'de');
        $this->savePage('home', 'en', 'Changed source');

        $result = $this->freshness->mark('home', 'en', 'de', ContentRevision::of($source), ContentRevision::of($target), 'alice');

        self::assertSame(409, $result['status']);
        self::assertSame('unknown', $this->freshness->status('home', 'en', 'de')['data']['freshness']);
    }

    public function testMarkerWritesUseCompareAndSwapAndCorruptMetadataFailsClosed(): void
    {
        $source = $this->content->draftPage('home', 'en');
        $target = $this->content->draftPage('home', 'de');
        $sourceRevision = ContentRevision::of($source);
        $targetRevision = ContentRevision::of($target);
        $marked = $this->freshness->mark('home', 'en', 'de', $sourceRevision, $targetRevision, 'alice', null);
        self::assertSame('reviewed', $marked['data']['freshness']);

        $raced = $this->freshness->mark('home', 'en', 'de', $sourceRevision, $targetRevision, 'bob', null);
        self::assertSame(409, $raced['status']);
        $replaced = $this->freshness->mark('home', 'en', 'de', $sourceRevision, $targetRevision, 'bob', $marked['data']['reviewedAt']);
        self::assertSame('bob', $replaced['data']['reviewedBy']);

        $path = glob($this->base . '/data/translation-reviews/*.json')[0];
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(0o600, fileperms($path . '.lock') & 0o777);
        self::assertSame(0o700, fileperms(dirname($path)) & 0o777);
        file_put_contents($path, '{broken');
        self::assertSame('unknown', $this->freshness->status('home', 'en', 'de')['data']['freshness']);
    }

    public function testStoreRefusesSymlinkedReviewDirectory(): void
    {
        $outside = $this->base . '/outside';
        mkdir($outside, 0o700);
        symlink($outside, $this->base . '/data/translation-reviews');
        $source = $this->content->draftPage('home', 'en');
        $target = $this->content->draftPage('home', 'de');
        $service = new TranslationFreshnessService(
            $this->content,
            new JsonTranslationReviewStore($this->base . '/data/translation-reviews'),
        );

        $this->expectException(\RuntimeException::class);
        $service->mark('home', 'en', 'de', ContentRevision::of($source), ContentRevision::of($target), 'alice', null);
    }

    public function testBothPagesMustExistAndLocalesMustDiffer(): void
    {
        self::assertSame(404, $this->freshness->status('home', 'fr', 'de')['status']);
        self::assertSame(400, $this->freshness->status('home', 'en', 'en')['status']);
        self::assertSame(404, $this->freshness->mark('missing', 'en', 'de', 'a', 'b', 'alice')['status']);
    }
}
