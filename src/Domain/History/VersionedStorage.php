<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;

/**
 * Storage that retains what it overwrites.
 *
 * An extension of the storage port rather than a replacement, so everything
 * that already takes a {@see StorageInterface} keeps working and history is
 * something a backend gains rather than something callers have to know about.
 *
 * The single addition exists because a restore is a save that needs to be
 * labelled as one. Every other write can infer its own reason, but only the
 * caller knows that this particular write is somebody putting yesterday's copy
 * back, and an editor reading the history needs to be told the difference.
 */
interface VersionedStorage extends StorageInterface
{
    public function saveWithReason(Content $content, string $reason): void;
}
