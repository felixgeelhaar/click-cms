<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Update;

/**
 * What should happen about the releases on offer — the one place the question
 * "is there an update, and may we install it ourselves?" is answered.
 *
 * Pure: it is handed the current version, the policy and the candidate releases,
 * and returns a decision. No network, no filesystem, no clock — so every rule
 * below is testable directly, which matters because these rules decide when a
 * machine runs new code unattended.
 */
final class UpdateDecision
{
    private function __construct(
        public readonly ?Release $release,
        public readonly UpdateStep $step,
        /** True when the policy permits installing this with no human present. */
        public readonly bool $automatic,
        /** Why nothing is on offer, when nothing is. Empty when there is. */
        public readonly string $reason,
    ) {
    }

    public function hasUpdate(): bool
    {
        return $this->release !== null;
    }

    /**
     * Pick the best release the site can actually run and decide how to treat it.
     *
     * Prefer the newest applicable release permitted by the unattended policy.
     * When none is permitted, offer the newest applicable release for review.
     * Applicable releases are newer than the running version, support this PHP,
     * and are stable unless pre-releases were requested.
     *
     * @param list<Release> $releases
     */
    public static function decide(
        SemanticVersion $current,
        array $releases,
        UpdatePolicy $policy,
        string $phpVersion,
        bool $allowPreRelease = false,
    ): self {
        if (!$policy->checksForUpdates()) {
            return new self(null, UpdateStep::None, false, 'Updates are set to manual.');
        }

        $applicable = [];
        foreach ($releases as $release) {
            if (!$release->version->isNewerThan($current)) {
                continue;
            }
            if ($release->version->isPreRelease() && !$allowPreRelease) {
                continue;
            }
            if (!$release->runsOn($phpVersion)) {
                continue;
            }
            $applicable[] = $release;
        }

        if ($applicable === []) {
            return new self(null, UpdateStep::None, false, 'Already up to date.');
        }

        usort($applicable, static fn (Release $a, Release $b): int => $a->version->compare($b->version));
        $best = end($applicable);

        // Prefer the newest release the policy can actually install. Otherwise
        // a newer major (or opted-in beta) would indefinitely block a stable
        // compatible update. Keep the newest offer for notification when no
        // unattended candidate exists.
        $carriesSecurityFix = false;
        $automaticCandidate = null;
        foreach ($applicable as $release) {
            $carriesSecurityFix = $carriesSecurityFix || $release->security;
            if ($policy->allowsAutomatic(
                $release->version->stepFrom($current),
                $carriesSecurityFix,
                $release->version->isPreRelease(),
            )) {
                $automaticCandidate = $release;
            }
        }

        $selected = $automaticCandidate ?? $best;
        return new self(
            release: $selected,
            step: $selected->version->stepFrom($current),
            automatic: $automaticCandidate !== null,
            reason: '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hasUpdate' => $this->hasUpdate(),
            'step' => $this->step->value,
            'automatic' => $this->automatic,
            'reason' => $this->reason,
            'release' => $this->release?->toArray(),
        ];
    }
}
