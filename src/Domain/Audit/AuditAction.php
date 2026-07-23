<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Audit;

/**
 * What an audit entry records having happened to a document.
 *
 * A closed set rather than a free-text verb, for the same reason roles map to
 * named capabilities in one file: an audit trail is only evidence if its
 * vocabulary is fixed. "updated" written three different ways is three events a
 * reader cannot count, and a typo in a logged verb is a record that quietly
 * means nothing. The set is deliberately small — it names the writes core can
 * observe and no more, because inventing an action the code never records would
 * describe a system that does not exist.
 *
 * The distinctions here are the ones that carry different weight when something
 * has gone wrong. A restore is separated from an ordinary update because it is
 * the one write whose purpose is to undo somebody else's; a publish from a save
 * because it is the moment private work became public; a delete from an
 * unpublish because one removes the working copy and the other only takes the
 * live page down. Those are exactly the events the order-of-work names — a
 * restore replacing a working copy, a publish changing what the public sees.
 */
enum AuditAction: string
{
    /** A document written where none stood before. */
    case Created = 'created';

    /** An existing document's working copy changed. */
    case Updated = 'updated';

    /** A document removed, working copy and all. */
    case Deleted = 'deleted';

    /** The working copy promoted into the live site. */
    case Published = 'published';

    /** A live document taken back down, its versions left standing. */
    case Unpublished = 'unpublished';

    /** An earlier version put back as the working copy. */
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
            self::Restored => 'Restored',
        };
    }
}
