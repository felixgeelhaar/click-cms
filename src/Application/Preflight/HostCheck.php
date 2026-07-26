<?php

declare(strict_types=1);

namespace Click\Cms\Application\Preflight;

/**
 * One thing the preflight looked at, and what it found.
 *
 * The detail is written for whoever has to act on it, so it says what the
 * consequence is rather than only what the setting is: "2 MB — smaller than the
 * 64 MB the CMS accepts" is actionable where "upload_max_filesize = 2M" is a
 * fact the reader has to interpret.
 */
final class HostCheck
{
    public function __construct(
        public readonly string $name,
        public readonly CheckStatus $status,
        public readonly string $detail,
    ) {}

    /** Whether this stops the CMS running here. */
    public function isFailure(): bool
    {
        return $this->status === CheckStatus::Failed;
    }
}
