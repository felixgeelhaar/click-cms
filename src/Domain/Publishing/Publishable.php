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
     * Pages, and for now only pages.
     *
     * Adding a type here is a deliberate act: it changes what saving that type
     * means everywhere at once.
     */
    private const TYPES = ['page'];

    private function __construct() {}

    public static function includes(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return self::TYPES;
    }
}
