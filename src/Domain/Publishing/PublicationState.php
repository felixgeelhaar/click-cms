<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;

/**
 * What an editor needs to know about where a document stands.
 *
 * There is deliberately no `status` field on the document any more. Publication
 * is presence in `content/` — that is what the public read path consults, and a
 * stored field claiming otherwise could only ever be the wrong one of two
 * answers. A page marked `published` that had been removed from `content/`
 * would show as live in the admin UI and 404 to every visitor, and nothing in
 * the system could say which was right.
 *
 * So the three states a UI actually asks about are derived, here, from the two
 * facts that cannot disagree: what is in `content/`, and what the version chain
 * holds.
 *
 * - **published** — there is a live document at this address.
 * - **hasUnpublishedChanges** — the working copy differs from what is live.
 * - **neverPublished** — this address has never been live, which is a different
 *   thing from having been taken down and reads differently in a listing.
 */
final class PublicationState
{
    private function __construct(
        public readonly bool $published,
        public readonly bool $hasUnpublishedChanges,
        public readonly bool $neverPublished,
        /** When the current live document was promoted, if that is knowable. */
        public readonly ?string $publishedAt,
    ) {}

    /**
     * @param ?Content $live          The document in `content/`, if any.
     * @param ?Version $newest        The newest retained version — the working copy.
     * @param ?Version $lastPublished The newest version recorded by a publish.
     */
    public static function of(?Content $live, ?Version $newest, ?Version $lastPublished): self
    {
        $published = $live !== null;

        return new self(
            $published,
            self::pending($live, $newest, $published),
            // A document that is live has plainly been published, even when no
            // publish was ever recorded — content seeded straight onto disk by
            // an installer or a migration is the ordinary case of that, and
            // reporting it as "never published" would be a lie the editor could
            // see through by loading the page.
            !$published && $lastPublished === null,
            $published ? $lastPublished?->recordedAt->format(DATE_ATOM) : null,
        );
    }

    private static function pending(?Content $live, ?Version $newest, bool $published): bool
    {
        // No version chain at all means the stored document is the only copy
        // there is, so there is nothing pending. This is the seeded-content case
        // again, and treating it as a pending change would put a "publish me"
        // badge on every page of a fresh install.
        if ($newest === null) {
            return false;
        }

        // A deleted working copy over a live document is a removal waiting to
        // happen, and the editor should be told so rather than seeing the page
        // reported as clean while it is still public.
        if ($newest->reason === Version::REASON_DELETE) {
            return $published;
        }

        // Data only, not timestamps. Publishing writes the document through
        // unchanged, so the live copy and the version it came from differ in
        // nothing an editor would call a change — but they were written at
        // different instants, and comparing whole documents would report every
        // published page as dirty for ever.
        return ($newest->document['data'] ?? []) !== $live?->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'published' => $this->published,
            'hasUnpublishedChanges' => $this->hasUnpublishedChanges,
            'neverPublished' => $this->neverPublished,
            'publishedAt' => $this->publishedAt,
        ];
    }
}
