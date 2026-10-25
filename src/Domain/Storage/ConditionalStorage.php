<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Storage;

use Click\Cms\Domain\Content\Content;

/** Storage that can reject a write when its working copy has changed. */
interface ConditionalStorage extends StorageInterface
{
    /**
     * Save only when the current working copy still has this revision.
     *
     * @return bool True when saved; false when the revision is stale.
     */
    public function saveIfUnchanged(Content $content, string $expectedRevision): bool;
}
