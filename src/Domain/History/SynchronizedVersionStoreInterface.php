<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

use Click\Cms\Domain\ValueObjects\ContentKey;

/** Optional capability for serialising mutations of one version chain. */
interface SynchronizedVersionStoreInterface extends VersionStoreInterface
{
    /** Run an operation while holding the key's exclusive mutation lock. */
    public function synchronize(ContentKey $key, callable $operation): mixed;
}
