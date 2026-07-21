<?php

declare(strict_types=1);

namespace Click\Cms\Domain\History;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One retained state of a document.
 *
 * A whole snapshot rather than a diff. Diffs are smaller but chain: one corrupt
 * link and every version behind it becomes unreadable, which is the opposite of
 * what a safety net is for. Documents here are a few kilobytes of JSON, so the
 * space argument never gets to be made.
 *
 * A version records the state that was *written*, not the state that was
 * replaced. That is what makes the author knowable: at the moment of a write we
 * know who is writing, whereas whoever produced the state being overwritten is
 * lost unless it was recorded at the time. The consequence is that the current
 * document is also the newest version, and history is simply every state the
 * document has been in.
 *
 * The one exception is {@see REASON_DELETE}, where the snapshot is the state
 * immediately before removal. In every case `author` means "who performed the
 * write that produced this record".
 */
final class Version
{
    /** Written by an ordinary save. */
    public const REASON_SAVE = 'save';

    /** Written by putting an earlier version back. */
    public const REASON_RESTORE = 'restore';

    /** The state the document was in immediately before it was deleted. */
    public const REASON_DELETE = 'delete';

    /**
     * Identifiers are a UTC timestamp to the microsecond plus entropy.
     *
     * Fixed width, so sorting them as strings sorts them chronologically —
     * which is what retention and listing both rely on — and legible enough
     * that a directory listing can be read by a human.
     */
    private const ID_PATTERN = '/^\d{8}T\d{6}\.\d{6}Z-[a-f0-9]{4,16}$/';

    /**
     * @param array<string, mixed> $document A {@see Content::toArray()} payload.
     */
    private function __construct(
        public readonly string $id,
        public readonly ContentKey $key,
        public readonly array $document,
        public readonly DateTimeImmutable $recordedAt,
        public readonly ?string $author,
        public readonly string $reason,
    ) {}

    public static function of(
        string $id,
        Content $content,
        DateTimeImmutable $recordedAt,
        ?string $author,
        string $reason = self::REASON_SAVE,
    ): self {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException("Not a valid version identifier: {$id}");
        }

        return new self(
            $id,
            $content->key,
            $content->toArray(),
            $recordedAt,
            self::normaliseAuthor($author),
            self::normaliseReason($reason),
        );
    }

    /**
     * Build an identifier for a moment.
     *
     * Entropy is supplied rather than generated so this stays a pure function —
     * two writes can land in the same microsecond, and the caller is the one
     * that can retry on a collision.
     */
    public static function mintId(DateTimeImmutable $at, string $entropy): string
    {
        if (preg_match('/^[a-f0-9]{4,16}$/', $entropy) !== 1) {
            throw new InvalidArgumentException('Version entropy must be 4 to 16 hex characters.');
        }

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis.u\Z') . '-' . $entropy;
    }

    /**
     * Whether a string could be an identifier this class produced.
     *
     * Identifiers arrive from URLs and become path segments, so a backend must
     * be able to reject one before it is used to name a file.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Rebuild from a stored record.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        foreach (['id', 'key', 'recordedAt', 'reason'] as $required) {
            if (!isset($row[$required]) || !is_string($row[$required])) {
                throw new InvalidArgumentException("Stored version is missing its \"{$required}\".");
            }
        }

        if (!self::isValidId($row['id'])) {
            throw new InvalidArgumentException("Stored version has an invalid identifier: {$row['id']}");
        }

        $document = $row['document'] ?? null;
        if (!is_array($document)) {
            throw new InvalidArgumentException('Stored version "document" must be an array.');
        }

        $author = $row['author'] ?? null;

        return new self(
            $row['id'],
            ContentKey::fromString($row['key']),
            $document,
            new DateTimeImmutable($row['recordedAt']),
            self::normaliseAuthor(is_string($author) ? $author : null),
            self::normaliseReason($row['reason']),
        );
    }

    /**
     * The document as it was, ready to be written back.
     */
    public function content(): Content
    {
        return Content::fromArray($this->document);
    }

    /**
     * Who owned the document in this state, if anyone did.
     *
     * Needed to decide whether a restore is permitted when the document has
     * since been deleted and there is no current owner left to ask about.
     */
    public function owner(): ?string
    {
        $data = $this->document['data'] ?? [];
        $owner = is_array($data) ? ($data['owner'] ?? null) : null;

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * What a history list needs, without the whole document.
     *
     * Listing twenty versions of a page would otherwise return twenty full
     * copies of it, when all the screen shows is when and by whom.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'recordedAt' => $this->recordedAt->format(DATE_ATOM),
            'author' => $this->author,
            'reason' => $this->reason,
            'title' => $this->content()->title(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key->toString(),
            'recordedAt' => $this->recordedAt->format(DATE_ATOM),
            'author' => $this->author,
            'reason' => $this->reason,
            'document' => $this->document,
        ];
    }

    private static function normaliseAuthor(?string $author): ?string
    {
        $author = trim((string) $author);

        // An empty author and an absent one are the same fact — nobody
        // identifiable made this write — and storing both invites callers to
        // check for one and not the other.
        return $author === '' ? null : $author;
    }

    /**
     * An unrecognised reason becomes "save".
     *
     * A version recorded under a reason this version of the code does not know
     * is still a version, and refusing to read it would lose the very work
     * history exists to protect.
     */
    private static function normaliseReason(string $reason): string
    {
        return in_array($reason, [self::REASON_SAVE, self::REASON_RESTORE, self::REASON_DELETE], true)
            ? $reason
            : self::REASON_SAVE;
    }
}
