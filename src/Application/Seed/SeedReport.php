<?php

declare(strict_types=1);

namespace Click\Cms\Application\Seed;

/**
 * What a seeding run did.
 *
 * Three outcomes, kept apart because they mean different things to whoever ran
 * it: something was created, something was already there and was left alone, or
 * something could not be created and the run continued anyway. Collapsing the
 * last two — reporting a rejected page as "skipped" — would let a broken
 * section schema seed a half-site in silence, which is exactly the failure this
 * separation exists to make loud.
 */
final class SeedReport
{
    /** @var list<string> */
    private array $created = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $failures = [];

    public function created(string $what): void
    {
        $this->created[] = $what;
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
    public function createdItems(): array
    {
        return $this->created;
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
     * True when the run created nothing because everything was already present
     * — the ordinary result of running the seeder twice, and not an error.
     */
    public function wasNoOp(): bool
    {
        return $this->created === [] && $this->failures === [];
    }
}
