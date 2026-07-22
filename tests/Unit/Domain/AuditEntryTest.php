<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Audit\AuditAction;
use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\ValueObjects\ContentKey;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    /**
     * The whole point of the record: who did what, to which document, and when.
     * If any one of those four cannot be read back, the entry is not evidence.
     */
    public function testRecordsWhoWhatWhichAndWhen(): void
    {
        $at = new DateTimeImmutable('2026-07-22T10:30:00+00:00');

        $entry = AuditEntry::of(
            'ada',
            AuditAction::Updated,
            ContentKey::page('home'),
            $at,
        );

        $this->assertSame('ada', $entry->actor);
        $this->assertSame(AuditAction::Updated, $entry->action);
        $this->assertSame('page:en:home', $entry->document);
        $this->assertSame($at->getTimestamp(), $entry->recordedAt->getTimestamp());
    }

    /**
     * The domain performs no I/O, so it does not read the clock. The moment is
     * supplied by the caller — anything else would make this untestable without
     * freezing time and would put a side effect in the one layer forbidden them.
     */
    public function testTheTimestampIsSuppliedNotRead(): void
    {
        $at = new DateTimeImmutable('2000-01-01T00:00:00+00:00');

        $entry = AuditEntry::of('ada', AuditAction::Created, ContentKey::page('home'), $at);

        $this->assertEquals($at, $entry->recordedAt);
    }

    /**
     * A system write — a migration, a CLI import, a plugin with no session —
     * has no author, and attributing it to whoever happens to be first in the
     * user list would be a worse record than an honest "nobody identifiable".
     */
    public function testTheActorIsNullableForSystemWrites(): void
    {
        $entry = AuditEntry::of(null, AuditAction::Created, ContentKey::page('home'), new DateTimeImmutable());

        $this->assertNull($entry->actor);
    }

    /**
     * An empty actor and an absent one are the same fact, and storing both
     * invites a caller to check for one and miss the other.
     */
    public function testAnEmptyActorIsTheSameAsNone(): void
    {
        $entry = AuditEntry::of('   ', AuditAction::Created, ContentKey::page('home'), new DateTimeImmutable());

        $this->assertNull($entry->actor);
    }

    public function testAShortDetailIsOptional(): void
    {
        $bare = AuditEntry::of('ada', AuditAction::Deleted, ContentKey::page('home'), new DateTimeImmutable());
        $noted = AuditEntry::of('ada', AuditAction::Restored, ContentKey::page('home'), new DateTimeImmutable(), 'from 20260101T000000.000000Z-abcd');

        $this->assertNull($bare->detail);
        $this->assertSame('from 20260101T000000.000000Z-abcd', $noted->detail);
    }

    public function testRoundTripsThroughAnArray(): void
    {
        $at = new DateTimeImmutable('2026-07-22T10:30:00+00:00');
        $entry = AuditEntry::of('ada', AuditAction::Published, ContentKey::page('home', 'de'), $at, 'went live');

        $restored = AuditEntry::fromArray($entry->toArray());

        $this->assertSame('ada', $restored->actor);
        $this->assertSame(AuditAction::Published, $restored->action);
        $this->assertSame('page:de:home', $restored->document);
        $this->assertSame($at->getTimestamp(), $restored->recordedAt->getTimestamp());
        $this->assertSame('went live', $restored->detail);
    }

    public function testFromArrayRejectsAMissingDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEntry::fromArray([
            'actor' => 'ada',
            'action' => 'updated',
            'recordedAt' => '2026-07-22T10:30:00+00:00',
        ]);
    }

    public function testFromArrayRejectsAnUnknownAction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEntry::fromArray([
            'actor' => 'ada',
            'action' => 'annihilated',
            'document' => 'page:en:home',
            'recordedAt' => '2026-07-22T10:30:00+00:00',
        ]);
    }
}
