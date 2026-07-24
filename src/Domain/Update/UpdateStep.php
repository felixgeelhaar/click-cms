<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Update;

/**
 * How big a step an available update is. The policy decides what may be applied
 * without asking, and it decides on this rather than on raw version numbers.
 */
enum UpdateStep: string
{
    case None = 'none';
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';
}
