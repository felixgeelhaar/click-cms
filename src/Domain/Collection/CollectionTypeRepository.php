<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Collection;

/**
 * Source of the collection types a site offers.
 *
 * A port rather than a concrete loader, for the same reason section types have
 * one: the definitions live in files today and could come from a database or a
 * plugin tomorrow without anything above this noticing.
 */
interface CollectionTypeRepository
{
    /**
     * Every collection type the site declares, ordered for display.
     *
     * @return list<CollectionType>
     */
    public function all(): array;

    public function find(string $id): ?CollectionType;

    /**
     * Problems found while loading, keyed by source. Returned rather than
     * thrown so one malformed definition cannot take the admin UI down.
     *
     * @return array<string, string>
     */
    public function errors(): array;
}
