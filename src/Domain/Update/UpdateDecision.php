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
     * "Best" is the newest that (a) is newer than what is running, (b) runs on
     * this PHP, and (c) is stable unless pre-releases were asked for. A security
     * release is preferred over a newer non-security one only insofar as it is
     * newer — the newest applicable release already includes earlier fixes.
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

        $step = $best->version->stepFrom($current);

        // A security fix anywhere in the skipped range makes this update a
        // security update: taking the newest release also takes that fix, and an
        // administrator who set the policy to "security" means to receive it.
        $carriesSecurityFix = false;
        foreach ($applicable as $release) {
            if ($release->security) {
                $carriesSecurityFix = true;
                break;
            }
        }

        return new self(
            release: $best,
            step: $step,
            automatic: $policy->allowsAutomatic($step, $carriesSecurityFix, $best->version->isPreRelease()),
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
