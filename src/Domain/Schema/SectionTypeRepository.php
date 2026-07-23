<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

/**
 * Source of the section types a site offers.
 *
 * A port rather than a concrete loader so section types can come from files
 * today and from a database, a plugin or a remote service later without the
 * admin UI or the API noticing.
 */
interface SectionTypeRepository
{
    /**
     * Every section type the site declares, ordered for display.
     *
     * @return list<SectionType>
     */
    public function all(): array;

    public function find(string $id): ?SectionType;

    public function has(string $id): bool;

    /**
     * Problems found while loading, keyed by source (usually a filename).
     *
     * Returned rather than thrown: one malformed definition must not take the
     * whole admin UI down, but the author still needs to be told about it.
     *
     * @return array<string, string>
     */
    public function errors(): array;
}
