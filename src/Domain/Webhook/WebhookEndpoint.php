<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * Somewhere this site sends events, and what it sends there.
 *
 * The interesting behaviour is {@see subscribesTo()}, and specifically that it
 * asks whether the endpoint is active as part of answering. Activity is exactly
 * the check a caller forgets, and forgetting it here has a nastier consequence
 * than usual: deliveries would queue against an endpoint an administrator
 * deliberately switched off, and then all fire at once when it came back.
 */
final class WebhookEndpoint
{
    /**
     * @param list<string> $events Event names, or `family.*`, or `*`.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $secret,
        public readonly array $events,
        public readonly bool $active = true,
        public readonly string $description = '',
        public readonly ?string $createdAt = null,
    ) {}

    public function subscribesTo(string $event): bool
    {
        if (!$this->active) {
            return false;
        }

        foreach ($this->events as $pattern) {
            if ($pattern === '*' || $pattern === $event) {
                return true;
            }

            // `content.*` matches `content.published`. Only a trailing star, and
            // only on a dot boundary — a general glob here would let `*.*` and
            // `cont*` into a subscription language nobody asked for and every
            // reader would have to learn.
            if (str_ends_with($pattern, '.*')
                && str_starts_with($event, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $events = $row['events'] ?? [];

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['url'] ?? ''),
            (string) ($row['secret'] ?? ''),
            is_array($events) ? array_values(array_filter($events, 'is_string')) : [],
            (bool) ($row['active'] ?? true),
            (string) ($row['description'] ?? ''),
            isset($row['createdAt']) && is_string($row['createdAt']) ? $row['createdAt'] : null,
        );
    }

    /**
     * The stored shape, secret and all.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'secret' => $this->secret,
            'events' => $this->events,
            'active' => $this->active,
            'description' => $this->description,
            'createdAt' => $this->createdAt,
        ];
    }

    /**
     * The shape safe to put in a response.
     *
     * A separate method rather than a flag on {@see toArray()}, because the
     * difference between the two is one key and that key is the credential
     * authenticating every delivery. A boolean argument is something a caller
     * can get backwards; two differently named methods are not.
     *
     * The secret is shown exactly once, when the endpoint is created, and never
     * read back afterwards — the same arrangement every provider of these uses,
     * for the same reason: a secret that can be re-read is a secret that leaks
     * through any read-only view of the admin.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $shape = $this->toArray();
        unset($shape['secret']);

        return $shape;
    }
}
