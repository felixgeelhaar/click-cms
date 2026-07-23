<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

/**
 * How much history is kept, and which of it goes when there is too much.
 *
 * The rule is deliberately the dullest one available: keep the newest N, drop
 * the oldest first. Age-based expiry was the alternative and was rejected
 * because it makes the useful case worse — a page edited heavily in one
 * afternoon and then left alone loses exactly the versions an editor is most
 * likely to want back, while a page nobody touches keeps a single stale entry
 * forever. A count is also the thing an administrator can reason about: "the
 * last twenty" needs no explanation.
 *
 * Two versions are exempt, and this used to say there were none.
 *
 * That was correct while a version was only ever a copy of something already
 * safely on disk. It stopped being correct when the version chain became where
 * a document actually lives: the newest version is now the working copy, and
 * the version a publish recorded is the state the live site is serving. Under
 * the old rule the twenty-first edit to a page discarded the oldest version,
 * and after twenty-one edits without publishing, the version the live page came
 * from was gone — the site would still serve it, but nothing could say what it
 * was or put it back. That is data loss dressed up as tidiness, and it is
 * precisely the silent degradation this codebase keeps being bitten by.
 *
 * So the exemption is not a convenience, it is the difference between a
 * retention rule and a shredder pointed at the current document. It stays as
 * small as it can be — two entries, both nameable in a sentence — because a
 * retention rule with a list of special cases is one an editor cannot predict,
 * and an unpredictable safety net is worse than a small one.
 *
 * A consequence worth stating plainly: when an exempt version is old enough to
 * have been due for discarding, the total kept can exceed the limit by one. The
 * limit gives way rather than the exemption, because keeping twenty-one copies
 * of a few kilobytes is not a problem anybody has and losing the published one
 * is.
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
     * @param list<string> $ids   Version identifiers, oldest first.
     * @param list<string> $spare Identifiers that must survive whatever happens
     *        — the working copy and whatever the live site is serving. Anything
     *        here that is not in `$ids` is simply ignored, so a caller that
     *        cannot tell whether a document is published passes what it has.
     * @return list<string> The ones to remove, oldest first.
     */
    public function expired(array $ids, array $spare = []): array
    {
        $excess = count($ids) - $this->limit;

        if ($excess <= 0) {
            return [];
        }

        $exempt = array_flip($spare);
        $doomed = [];

        foreach ($ids as $id) {
            if ($excess <= 0) {
                break;
            }

            if (isset($exempt[$id])) {
                continue;
            }

            $doomed[] = $id;
            $excess--;
        }

        return $doomed;
    }
}
