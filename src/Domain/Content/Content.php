<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Content;

use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The content aggregate.
 *
 * A piece of content is identified by its {@see ContentKey} (type + slug) and
 * carries an open-ended `data` map. Keeping the payload open is deliberate:
 * plugins define their own fields, and the core must not need changing when
 * they do. The few keys the core itself relies on — `title`, `content`,
 * `status` — are read through accessors so callers never depend on the raw
 * shape.
 *
 * Instances are mutable through {@see update()} only, which merges rather than
 * replaces so a partial update cannot silently drop fields it did not mention.
 */
final class Content
{
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DRAFT = 'draft';

    private function __construct(
        public readonly ContentKey $key,
        public array $data,
        public readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function create(ContentKey $key, array $data = []): self
    {
        $now = new DateTimeImmutable();

        $createdAt = self::parseDate($data['createdAt'] ?? null) ?? $now;
        $updatedAt = self::parseDate($data['updatedAt'] ?? null) ?? $createdAt;

        unset($data['createdAt'], $data['updatedAt']);

        return new self($key, $data, $createdAt, $updatedAt);
    }

    /**
     * Rebuild from a stored array, preserving timestamps.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        if (!isset($row['key']) || !is_string($row['key'])) {
            throw new InvalidArgumentException('Stored content is missing its "key".');
        }

        $key = ContentKey::fromString($row['key']);
        $data = $row['data'] ?? [];

        if (!is_array($data)) {
            throw new InvalidArgumentException('Stored content "data" must be an array.');
        }

        $data['createdAt'] = $row['createdAt'] ?? null;
        $data['updatedAt'] = $row['updatedAt'] ?? null;

        return self::create($key, $data);
    }

    public function type(): string
    {
        return $this->key->type;
    }

    public function slug(): string
    {
        return $this->key->slug;
    }

    public function locale(): Locale
    {
        return $this->key->locale;
    }

    public function title(): string
    {
        $title = $this->data['title'] ?? null;

        // Fall back to the slug so a page missing a title still renders something
        // sensible rather than an empty heading.
        return is_string($title) && $title !== '' ? $title : $this->key->slug;
    }

    public function content(): string
    {
        $content = $this->data['content'] ?? null;

        return is_string($content) ? $content : '';
    }

    public function status(): string
    {
        $status = $this->data['status'] ?? null;

        return $status === self::STATUS_DRAFT ? self::STATUS_DRAFT : self::STATUS_PUBLISHED;
    }

    public function isPublished(): bool
    {
        return $this->status() === self::STATUS_PUBLISHED;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Merge new values into the payload.
     *
     * Merging rather than replacing means a caller that knows about three fields
     * cannot wipe a fourth it has never heard of — important when plugins own
     * their own keys. Pass null as a value to remove a field explicitly.
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): self
    {
        unset($data['createdAt'], $data['updatedAt']);

        foreach ($data as $field => $value) {
            if ($value === null) {
                unset($this->data[$field]);
                continue;
            }
            $this->data[$field] = $value;
        }

        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->toString(),
            'type' => $this->key->type,
            'slug' => $this->key->slug,
            // Alongside the composite key so a front end never has to parse the
            // key string to find out which language it is holding.
            'locale' => $this->key->locale->code,
            'data' => $this->data,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
        ];
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            // An unparseable stored timestamp must not stop content loading;
            // the caller falls back to "now".
            return null;
        }
    }
}
