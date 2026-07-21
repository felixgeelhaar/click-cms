<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

/**
 * How much history is kept, and which of it goes when there is too much.
 *
 * The rule is deliberately the dullest one available: keep the newest N, drop
 * the oldest first, no exemptions. Age-based expiry was the alternative and was
 * rejected because it makes the useful case worse — a page edited heavily in
 * one afternoon and then left alone loses exactly the versions an editor is
 * most likely to want back, while a page nobody touches keeps a single stale
 * entry forever. A count is also the thing an administrator can reason about:
 * "the last twenty" needs no explanation.
 *
 * Nothing is exempt, restores and pre-deletion snapshots included. A retention
 * rule with exceptions is one an editor cannot predict, and an unpredictable
 * safety net is worse than a small one.
 */
final class RetentionPolicy
{
    /**
     * Twenty is roughly a working day of edits on one document, and twenty
     * copies of a few kilobytes of JSON is nothing on any host this runs on.
     */
    public const DEFAULT_LIMIT = 20;

    private function __construct(public readonly int $limit) {}

    public static function keeping(int $limit): self
    {
        // A limit of zero would mean recording a version and discarding it in
        // the same breath: history that appears to be on and protects nothing.
        // Turning history off is a decision to make explicitly, not by leaving
        // a zero in a config file.
        return new self(max(1, $limit));
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_LIMIT);
    }

    /**
     * Which of these must be discarded.
     *
     * @param list<string> $ids Version identifiers, oldest first.
     * @return list<string> The ones to remove, oldest first.
     */
    public function expired(array $ids): array
    {
        $excess = count($ids) - $this->limit;

        return $excess <= 0 ? [] : array_slice($ids, 0, $excess);
    }
}
