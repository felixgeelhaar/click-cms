<?php

declare(strict_types=1);

namespace Click\Cms\Application\Translation;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\ContentRevision;
use Click\Cms\Domain\Translation\TranslationReviewStore;
use DateTimeImmutable;

/** Records and compares exact saved translation snapshots. */
final class TranslationFreshnessService
{
    public function __construct(
        private readonly ContentService $content,
        private readonly TranslationReviewStore $reviews,
    ) {}

    /** @return array<string, mixed> */
    public function status(string $slug, string $sourceLocale, string $targetLocale): array
    {
        if ($sourceLocale === $targetLocale) {
            return ['status' => 400, 'error' => 'Choose different source and target languages.'];
        }
        $source = $this->content->draftPage($slug, $sourceLocale);
        $target = $this->content->draftPage($slug, $targetLocale);
        if ($source === null || $target === null) {
            return ['status' => 404, 'error' => 'Both saved language versions must exist.'];
        }

        $sourceRevision = ContentRevision::of($source);
        $targetRevision = ContentRevision::of($target);
        $record = $this->reviews->find($slug, $sourceLocale, $targetLocale);
        if ($record === null) {
            return [
                'data' => [
                    'freshness' => 'unknown',
                    'slug' => $slug,
                    'sourceLocale' => $sourceLocale,
                    'targetLocale' => $targetLocale,
                    'sourceRevision' => null,
                    'targetRevision' => null,
                    'currentSourceRevision' => $sourceRevision,
                    'currentTargetRevision' => $targetRevision,
                    'reviewedAt' => null,
                    'reviewedBy' => null,
                    'staleReasons' => [],
                ],
            ];
        }

        $reasons = [];
        if (!hash_equals($record['sourceRevision'], $sourceRevision)) $reasons[] = 'source_changed';
        if (!hash_equals($record['targetRevision'], $targetRevision)) $reasons[] = 'target_changed';

        return [
            'data' => [
                'freshness' => $reasons === [] ? 'reviewed' : 'stale',
                'slug' => $slug,
                'sourceLocale' => $sourceLocale,
                'targetLocale' => $targetLocale,
                'sourceRevision' => $record['sourceRevision'],
                'targetRevision' => $record['targetRevision'],
                'currentSourceRevision' => $sourceRevision,
                'currentTargetRevision' => $targetRevision,
                'reviewedAt' => $record['reviewedAt'],
                'reviewedBy' => $record['reviewedBy'],
                'staleReasons' => $reasons,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function mark(
        string $slug,
        string $sourceLocale,
        string $targetLocale,
        string $expectedSourceRevision,
        string $expectedTargetRevision,
        string $reviewedBy,
        ?string $expectedReviewedAt = null,
    ): array {
        if ($sourceLocale === $targetLocale) {
            return ['status' => 400, 'error' => 'Choose different source and target languages.'];
        }
        if ($expectedSourceRevision === '' || $expectedTargetRevision === '' || $reviewedBy === '') {
            return ['status' => 400, 'error' => 'Current saved revisions and a signed-in reviewer are required.'];
        }

        $source = $this->content->draftPage($slug, $sourceLocale);
        $target = $this->content->draftPage($slug, $targetLocale);
        if ($source === null || $target === null) {
            return ['status' => 404, 'error' => 'Both saved language versions must exist.'];
        }
        $sourceRevision = ContentRevision::of($source);
        $targetRevision = ContentRevision::of($target);
        if (!hash_equals($sourceRevision, $expectedSourceRevision) || !hash_equals($targetRevision, $expectedTargetRevision)) {
            return [
                'status' => 409,
                'error' => 'A saved language version changed. Reload the comparison before marking it reviewed.',
                'data' => ['currentSourceRevision' => $sourceRevision, 'currentTargetRevision' => $targetRevision],
            ];
        }

        $record = [
            'slug' => $slug,
            'sourceLocale' => $sourceLocale,
            'targetLocale' => $targetLocale,
            'sourceRevision' => $sourceRevision,
            'targetRevision' => $targetRevision,
            'reviewedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
            'reviewedBy' => $reviewedBy,
        ];
        if (!$this->reviews->saveIfUnchanged($record, $expectedReviewedAt)) {
            return ['status' => 409, 'error' => 'Another reviewer updated this review marker. Reload the comparison and try again.'];
        }

        return $this->status($slug, $sourceLocale, $targetLocale);
    }
}
