<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Backup\BackupExporter;
use Click\Cms\Application\Backup\BackupRestorer;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

final class BackupDraftRecoveryTest extends BackupTestCase
{
    public function testCleanRestoreKeepsLiveAndUnpublishedWorkingCopiesSeparate(): void
    {
        $source = $this->versioning($this->sqliteStorage());
        $this->publishPage($source, 'home', ['title' => 'Live title']);
        $source->save(Content::create(ContentKey::page('home'), ['title' => 'Private revision']));
        $source->save(Content::create(ContentKey::page('secret'), ['title' => 'Never published']));
        $archive = $this->base . '/recovery.zip';
        (new BackupExporter($source, $this->mediaDir(), 'sqlite'))->export($archive);
        $target = $this->versioning($this->jsonStorage('-restored'), '-restored');
        $report = (new BackupRestorer($this->contentService($target), $this->base . '/restored-media'))->restore($archive);
        self::assertFalse($report->hasFailures(), implode('; ', $report->failureMessages()));
        self::assertSame('Live title', $target->find(ContentKey::page('home'))?->title());
        self::assertSame('Private revision', $target->draft(ContentKey::page('home'))?->title());
        self::assertNull($target->find(ContentKey::page('secret')));
        self::assertSame('Never published', $target->draft(ContentKey::page('secret'))?->title());
    }

    public function testEntirelyUnpublishedSiteIsIncludedAndOverwriteDoesNotPublishIt(): void
    {
        $source = $this->versioning($this->sqliteStorage());
        $source->save(Content::create(ContentKey::page('secret'), ['title' => 'Private']));
        $archive = $this->base . '/drafts.zip';
        (new BackupExporter($source, $this->mediaDir(), 'sqlite'))->export($archive);
        $target = $this->versioning($this->jsonStorage('-restored'), '-restored');
        $this->publishPage($target, 'secret', ['title' => 'Existing']);
        $restorer = new BackupRestorer($this->contentService($target), $this->base . '/restored-media');
        $restorer->restore($archive);
        self::assertSame('Existing', $target->find(ContentKey::page('secret'))?->title());
        $restorer->restore($archive, true);
        self::assertNull($target->find(ContentKey::page('secret')));
        self::assertSame('Private', $target->draft(ContentKey::page('secret'))?->title());
    }
    public function testDraftBearingArchiveRefusesAnUnversionedTargetBeforeWritingLiveContent(): void
    {
        $source = $this->versioning($this->sqliteStorage());
        $this->publishPage($source, 'home', ['title' => 'Live']);
        $source->save(Content::create(ContentKey::page('private'), ['title' => 'Private']));
        $archive = $this->base . '/drafts.zip';
        (new BackupExporter($source, $this->mediaDir(), 'sqlite'))->export($archive);
        $target = $this->jsonStorage('-plain');
        try {
            (new BackupRestorer($this->contentService($target), $this->base . '/media-plain'))->restore($archive);
            self::fail('A plain target would publish private working copies.');
        } catch (\Click\Cms\Application\Backup\BackupException $error) {
            self::assertStringContainsString('unpublished', $error->getMessage());
        }
        self::assertNull($target->find(ContentKey::page('home')));
        self::assertNull($target->find(ContentKey::page('private')));
    }

    public function testTamperedDraftIsRejectedBeforeAnyLiveContentIsWritten(): void
    {
        $source = $this->versioning($this->sqliteStorage());
        $this->publishPage($source, 'home', ['title' => 'Live']);
        $archive = $this->base . '/drafts.zip';
        (new BackupExporter($source, $this->mediaDir(), 'sqlite'))->export($archive);
        $manifest = $this->manifestOf($archive);
        foreach ($manifest['documents'] as $entry) {
            if (($entry['publication'] ?? 'live') === 'draft') {
                $this->replaceEntry($archive, $entry['entry'], 'tampered');
            }
        }
        $target = $this->versioning($this->jsonStorage('-target'), '-target');
        try {
            (new BackupRestorer($this->contentService($target), $this->base . '/media-target'))->restore($archive);
            self::fail('A tampered draft was accepted.');
        } catch (\Click\Cms\Application\Backup\BackupException) {
            self::assertNull($target->find(ContentKey::page('home')));
        }
    }

}
