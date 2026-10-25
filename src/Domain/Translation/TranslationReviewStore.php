<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Translation;

/** Private operational records of which saved source/target pair was reviewed. */
interface TranslationReviewStore
{
    /** @return array<string, mixed>|null */
    public function find(string $slug, string $sourceLocale, string $targetLocale): ?array;

    /** @param array<string, mixed> $record Atomically replace only the observed record version. */
    public function saveIfUnchanged(array $record, ?string $expectedReviewedAt): bool;
}
