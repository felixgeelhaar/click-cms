<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Update;

/**
 * What the site is allowed to install without asking a human.
 *
 * Updating is remote code execution by design, so this is the safety dial and it
 * is deliberately explicit. The default is {@see self::Security}: a site left
 * alone still repairs itself against known vulnerabilities — the thing an
 * unattended CMS most needs — while anything that could change behaviour waits
 * for an administrator. That is WordPress's hard-won default, and the reasoning
 * behind it applies here unchanged.
 *
 * A major version is never installed automatically under any policy short of
 * {@see self::All}, because a major is exactly the release allowed to break
 * something.
 */
enum UpdatePolicy: string
{
    /** Never check, never notify. For a site managed entirely by a deploy pipeline. */
    case Manual = 'manual';

    /** Check and tell an administrator, but install nothing on its own. */
    case Notify = 'notify';

    /** Install security releases automatically; everything else waits. Default. */
    case Security = 'security';

    /** Install patch and minor releases automatically; a major still waits. */
    case Minor = 'minor';

    /** Install anything, including a major. For someone who really means it. */
    case All = 'all';

    public static function fromString(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Security;
    }

    /** Whether this policy looks for updates at all. */
    public function checksForUpdates(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Whether a release may be installed with no administrator present.
     *
     * A pre-release is never automatic: opting into a beta channel is a decision
     * to watch what happens, which is the opposite of unattended.
     */
    public function allowsAutomatic(UpdateStep $step, bool $isSecurity, bool $isPreRelease = false): bool
    {
        if ($step === UpdateStep::None || $isPreRelease) {
            return false;
        }

        return match ($this) {
            self::Manual, self::Notify => false,
            // A security release is worth taking at any size below major: the risk
            // of the known hole beats the risk of the change.
            self::Security => $isSecurity && $step !== UpdateStep::Major,
            self::Minor => $step === UpdateStep::Patch || $step === UpdateStep::Minor,
            self::All => true,
        };
    }
}
