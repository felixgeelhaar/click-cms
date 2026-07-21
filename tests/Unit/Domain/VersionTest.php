<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\ValueObjects\ContentKey;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    private function version(string $reason = Version::REASON_SAVE, ?string $author = 'ada'): Version
    {
        $at = new DateTimeImmutable('2026-07-21 10:45:12.123456', new DateTimeZone('UTC'));

        return Version::of(
            Version::mintId($at, 'a3f9'),
            Content::create(ContentKey::page('home'), ['title' => 'Home', 'owner' => 'ada']),
            $at,
            $author,
            $reason
        );
    }

    public function testIdentifiersAreUtcTimestampsPlusEntropy(): void
    {
        $at = new DateTimeImmutable('2026-07-21 10:45:12.123456', new DateTimeZone('UTC'));

        $this->assertSame('20260721T104512.123456Z-a3f9', Version::mintId($at, 'a3f9'));
    }

    /**
     * Retention and listing both sort identifiers as strings, so the string
     * order has to be the chronological one.
     */
    public function testIdentifiersSortChronologicallyAsStrings(): void
    {
        $utc = new DateTimeZone('UTC');
        $ids = [
            Version::mintId(new DateTimeImmutable('2026-07-21 10:45:12.000002', $utc), 'ffff'),
            Version::mintId(new DateTimeImmutable('2026-01-02 03:04:05.000000', $utc), 'aaaa'),
            Version::mintId(new DateTimeImmutable('2026-07-21 10:45:12.000001', $utc), '0000'),
        ];

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        $this->assertSame([$ids[1], $ids[2], $ids[0]], $sorted);
    }

    public function testAMomentIsNormalisedToUtcBeforeBecomingAnIdentifier(): void
    {
        $berlin = new DateTimeImmutable('2026-07-21 12:45:12.123456', new DateTimeZone('Europe/Berlin'));

        $this->assertSame('20260721T104512.123456Z-a3f9', Version::mintId($berlin, 'a3f9'));
    }

    public function testEntropyThatIsNotHexIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Version::mintId(new DateTimeImmutable(), '../..');
    }

    /**
     * Identifiers arrive from URLs and become path segments.
     */
    public function testOnlyIdentifiersThisClassCouldHaveProducedAreValid(): void
    {
        $this->assertTrue(Version::isValidId('20260721T104512.123456Z-a3f9'));

        $this->assertFalse(Version::isValidId('../../etc/passwd'));
        $this->assertFalse(Version::isValidId('20260721T104512.123456Z-a3f9/../escape'));
        $this->assertFalse(Version::isValidId('latest'));
        $this->assertFalse(Version::isValidId(''));
        $this->assertFalse(Version::isValidId('20260721T104512Z-a3f9'));
    }

    public function testConstructingWithAnUnusableIdentifierThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Version::of('latest', Content::create(ContentKey::page('home')), new DateTimeImmutable(), null);
    }

    public function testRoundTripsThroughItsStoredForm(): void
    {
        $original = $this->version(Version::REASON_RESTORE);
        $restored = Version::fromArray($original->toArray());

        $this->assertSame($original->id, $restored->id);
        $this->assertSame('page:home', $restored->key->toString());
        $this->assertSame('ada', $restored->author);
        $this->assertSame(Version::REASON_RESTORE, $restored->reason);
        $this->assertSame($original->document, $restored->document);
    }

    public function testTheSnapshotRebuildsIntoTheDocumentItWas(): void
    {
        $content = $this->version()->content();

        $this->assertSame('Home', $content->title());
        $this->assertSame('page:home', $content->key->toString());
    }

    /**
     * A listing screen shows when and by whom, so it must not have to carry
     * twenty full copies of the page to do it.
     */
    public function testSummaryOmitsTheDocument(): void
    {
        $summary = $this->version()->summary();

        $this->assertSame(
            ['id', 'recordedAt', 'author', 'reason', 'title'],
            array_keys($summary)
        );
        $this->assertSame('Home', $summary['title']);
        $this->assertSame('ada', $summary['author']);
    }

    public function testAnUnknownAuthorIsNullRatherThanEmpty(): void
    {
        $this->assertNull($this->version(author: '')->author);
        $this->assertNull($this->version(author: '   ')->author);
        $this->assertNull($this->version(author: null)->author);
    }

    public function testOwnerIsReadableFromTheSnapshotAfterTheDocumentIsGone(): void
    {
        $this->assertSame('ada', $this->version()->owner());
    }

    /**
     * A version written under a reason this build does not know is still a
     * version; refusing to read it would lose the work history exists to keep.
     */
    public function testAnUnrecognisedReasonReadsAsAnOrdinarySave(): void
    {
        $this->assertSame(Version::REASON_SAVE, $this->version('nonsense')->reason);
    }

    public function testAStoredRecordMissingItsIdentifierIsRefused(): void
    {
        $row = $this->version()->toArray();
        unset($row['id']);

        $this->expectException(InvalidArgumentException::class);
        Version::fromArray($row);
    }

    public function testAStoredRecordWithoutADocumentIsRefused(): void
    {
        $row = $this->version()->toArray();
        $row['document'] = 'not an array';

        $this->expectException(InvalidArgumentException::class);
        Version::fromArray($row);
    }
}
