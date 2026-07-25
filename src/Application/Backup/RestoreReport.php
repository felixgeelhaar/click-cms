<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

/**
 * What a restore did.
 *
 * Three outcomes kept apart, for the reason {@see \Click\Cms\Application\Seed\SeedReport}
 * keeps them apart: restored, already there and left alone, or could not be
 * written. Collapsing the middle two would let a restore that failed on half the
 * site read as a restore that found half the site already present — and those
 * are opposite facts about whether anyone needs to act.
 *
 * "Left alone" is the default outcome for anything that already exists, because
 * a restore is far more often run to recover the few things that went missing
 * than to roll an entire site back. Overwriting by default would mean the
 * ordinary use of this feature destroys every edit made since the backup was
 * taken, which is a worse loss than the one being repaired.
 */
final class RestoreReport
{
    /** @var list<string> */
    private array $restored = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $failures = [];

    public function restored(string $what): void
    {
        $this->restored[] = $what;
    }

    public function skipped(string $what): void
    {
        $this->skipped[] = $what;
    }

    public function failed(string $what, string $why): void
    {
        $this->failures[] = $what . ': ' . $why;
    }

    /** @return list<string> */
    public function restoredItems(): array
    {
        return $this->restored;
    }

    /** @return list<string> */
    public function skippedItems(): array
    {
        return $this->skipped;
    }

    /** @return list<string> */
    public function failureMessages(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * True when nothing was written because everything in the archive was
     * already present — the ordinary result of restoring a backup onto the site
     * it was taken from, and not an error.
     */
    public function wasNoOp(): bool
    {
        return $this->restored === [] && $this->failures === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'restored' => $this->restored,
            'skipped' => $this->skipped,
            'failed' => $this->failures,
            'counts' => [
                'restored' => count($this->restored),
                'skipped' => count($this->skipped),
                'failed' => count($this->failures),
            ],
        ];
    }
}
