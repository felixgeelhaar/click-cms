<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Update;

use InvalidArgumentException;

/**
 * A semantic version, compared properly.
 *
 * The update system decides — without a human in the loop, when the policy says
 * so — whether one release supersedes another and whether the step is a patch, a
 * minor or a major. Doing that on strings ("1.10.0" < "1.9.0" alphabetically) is
 * how an auto-updater skips a security release or installs a breaking one, so
 * the comparison lives in a value object with its own tests rather than in an
 * `if` somewhere.
 *
 * Pre-release identifiers are parsed and ordered per semver (1.0.0-beta.2 comes
 * before 1.0.0), because a channel that offers pre-releases must still order
 * them; build metadata is ignored in comparison, as the spec requires.
 */
final class SemanticVersion
{
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        /** Empty when this is a stable release. */
        public readonly string $preRelease,
    ) {
    }

    public static function fromString(string $version): self
    {
        $parsed = self::tryFromString($version);
        if ($parsed === null) {
            throw new InvalidArgumentException("Not a semantic version: \"{$version}\".");
        }

        return $parsed;
    }

    /** Null rather than an exception, for parsing whatever a feed hands over. */
    public static function tryFromString(string $version): ?self
    {
        $value = trim($version);
        // A leading "v" is conventional in tags and release names.
        if ($value !== '' && ($value[0] === 'v' || $value[0] === 'V')) {
            $value = substr($value, 1);
        }

        $pattern = '/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';
        if (preg_match($pattern, $value, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? '');
    }

    public function isPreRelease(): bool
    {
        return $this->preRelease !== '';
    }

    /** -1 when this is older than $other, 0 when equal, 1 when newer. */
    public function compare(self $other): int
    {
        foreach ([[$this->major, $other->major], [$this->minor, $other->minor], [$this->patch, $other->patch]] as [$a, $b]) {
            if ($a !== $b) {
                return $a <=> $b;
            }
        }

        // A pre-release is older than the stable release it leads to.
        if ($this->preRelease === '' && $other->preRelease === '') {
            return 0;
        }
        if ($this->preRelease === '') {
            return 1;
        }
        if ($other->preRelease === '') {
            return -1;
        }

        return $this->comparePreRelease($this->preRelease, $other->preRelease);
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /**
     * How big a step it is from $current to this version. A downgrade or an
     * equal version reports `none`, so a caller never has to check twice.
     */
    public function stepFrom(self $current): UpdateStep
    {
        if (!$this->isNewerThan($current)) {
            return UpdateStep::None;
        }
        if ($this->major !== $current->major) {
            return UpdateStep::Major;
        }
        if ($this->minor !== $current->minor) {
            return UpdateStep::Minor;
        }

        return UpdateStep::Patch;
    }

    public function toString(): string
    {
        $base = "{$this->major}.{$this->minor}.{$this->patch}";

        return $this->preRelease === '' ? $base : "{$base}-{$this->preRelease}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** Dot-separated identifiers: numeric compare numerically, others as text. */
    private function comparePreRelease(string $a, string $b): int
    {
        $left = explode('.', $a);
        $right = explode('.', $b);

        $count = max(count($left), count($right));
        for ($i = 0; $i < $count; $i++) {
            // A shorter set of identifiers is lower when all preceding are equal.
            if (!isset($left[$i])) {
                return -1;
            }
            if (!isset($right[$i])) {
                return 1;
            }
            if ($left[$i] === $right[$i]) {
                continue;
            }

            $lNum = ctype_digit($left[$i]);
            $rNum = ctype_digit($right[$i]);
            if ($lNum && $rNum) {
                return (int) $left[$i] <=> (int) $right[$i];
            }
            // Numeric identifiers rank lower than alphanumeric ones.
            if ($lNum !== $rNum) {
                return $lNum ? -1 : 1;
            }

            return strcmp($left[$i], $right[$i]) <=> 0;
        }

        return 0;
    }
}
