<?php

declare(strict_types=1);

namespace Click\Cms\Application\Collection;

use Click\Cms\Domain\Collection\CollectionType;

/**
 * A public URL path, resolved to the one entry it names.
 *
 * Two fields rather than a tuple because both callers of {@see EntryRouter::match()}
 * need the *type* as well as the slug — one to read the entry, the other to render
 * it against the type's field schema — and an array shape that has to be
 * documented at every hop is the version of this that goes wrong.
 */
final class EntryAddress
{
    public function __construct(
        public readonly CollectionType $type,
        public readonly string $slug,
    ) {}
}
