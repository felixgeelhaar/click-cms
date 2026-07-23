<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Redirect;

/**
 * The set of redirect rules, and the one question the kernel asks it: does any
 * rule apply to this path?
 *
 * A malformed rule is dropped rather than allowed to throw — the rules are
 * consulted on the way to a 404, so one bad entry must not turn every unknown
 * URL into a 500. The rule that could not be built simply does not redirect.
 */
final class RedirectRules
{
    /** @var list<Redirect> */
    private array $rules;

    /**
     * @param list<Redirect> $rules
     */
    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param list<array<string, mixed>> $raw
     */
    public static function fromArray(array $raw): self
    {
        $rules = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            try {
                $rules[] = Redirect::fromArray($entry);
            } catch (\InvalidArgumentException) {
                // A bad rule is skipped, not fatal.
                continue;
            }
        }

        return new self($rules);
    }

    /**
     * The first rule that matches the path, or null. First-wins, so the order
     * they were entered is the order they are tried.
     */
    public function match(string $requestPath): ?Redirect
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($requestPath)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return list<array{from: string, to: string, permanent: bool}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Redirect $r): array => $r->toArray(), $this->rules);
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
