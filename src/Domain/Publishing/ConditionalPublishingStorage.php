<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

/** Atomic promotion of exactly the working copy checked by publication policy. */
interface ConditionalPublishingStorage extends PublishingStorage
{
    /** False is a stale revision; null means no working copy exists. */
    public function publishIfUnchanged(ContentKey $key, string $expectedRevision): Content|false|null;
}
