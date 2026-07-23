<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

/**
 * Which kinds of document have a published state at all.
 *
 * Draft-and-publish is not free: a publishable type stops being written
 * straight to `content/` and lives in the version chain until somebody promotes
 * it. That is exactly right for a page and nonsense for an account — nobody
 * drafts a login, and a user record that existed only as a version would make
 * signing in depend on whether anyone had pressed Publish. Media is the same:
 * the file is already on disk the moment it is uploaded, so a "draft" record
 * pointing at it would describe a state that does not exist.
 *
 * The list is stated here rather than inferred from a flag on the document,
 * because inferring it would let a caller create an unpublishable page or a
 * publishable user by sending the wrong payload. Being one named list also
 * makes the rule testable, which is the whole reason it is not simply a
 * `$type === 'page'` written in four places.
 */
final class Publishable
{
    /**
     * Pages are publishable in the code itself. Collections are publishable too,
     * but which ones exist is a site's decision expressed in config, not the
     * codebase's — so their ids are registered at boot rather than named here.
     * The rule is still one deliberate act per type: a page by being on this
     * list, a collection by a site declaring it. What is refused is inferring
     * publishability from a flag on a document, which would let a crafted payload
     * make a page unpublishable or an account publishable.
     */
    private const CORE_TYPES = ['page'];

    /** @var list<string> Collection type ids, registered from config at boot. */
    private static array $registered = [];

    private function __construct() {}

    /**
     * Declare additional publishable types — the site's collection ids. Additive
     * and idempotent, so booting twice or registering in two passes is safe.
     *
     * @param list<string> $types
     */
    public static function register(array $types): void
    {
        foreach ($types as $type) {
            if (is_string($type) && $type !== '' && !in_array($type, self::$registered, true)) {
                self::$registered[] = $type;
            }
        }
    }

    /** Clear the registered set. For tests that must not leak into one another. */
    public static function reset(): void
    {
        self::$registered = [];
    }

    public static function includes(string $type): bool
    {
        return in_array($type, self::CORE_TYPES, true)
            || in_array($type, self::$registered, true);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_unique([...self::CORE_TYPES, ...self::$registered]));
    }
}
