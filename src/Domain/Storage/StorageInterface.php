<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Persistence port for content.
 *
 * Declared in the domain so backends (JSON, SQLite, MySQL, Postgres) depend on
 * the domain rather than the reverse — swapping a backend must never require
 * touching domain or application code.
 *
 * Storage plugins return an implementation of this from their `storage.init`
 * hook, which is how the active backend is chosen at runtime.
 */
interface StorageInterface
{
    public function find(ContentKey $key): ?Content;

    /**
     * All content of a type, in stable order.
     *
     * Pass a locale to list one language. Null lists every language, which is
     * what a caller wants when it is reporting which translations exist; it is
     * not what a listing screen wants, so callers say which they mean.
     *
     * @return list<Content>
     */
    public function findByType(string $type, ?Locale $locale = null): array;

    public function save(Content $content): void;

    /** @return bool True when something was actually removed. */
    public function delete(ContentKey $key): bool;

    public function exists(ContentKey $key): bool;
}
