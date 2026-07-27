<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * Where the list of endpoints lives.
 *
 * Separate from {@see DeliveryQueue} rather than one webhook store, because the
 * two have nothing in common but the word: a handful of endpoints, read on
 * every event and changed by hand a few times a year, against a queue of
 * thousands of short-lived rows swept every minute. One interface over both
 * would force one storage shape onto two access patterns.
 */
interface EndpointRepository
{
    /** @return list<WebhookEndpoint> */
    public function all(): array;

    public function find(string $id): ?WebhookEndpoint;

    public function save(WebhookEndpoint $endpoint): void;

    public function delete(string $id): bool;

    /**
     * Every endpoint that wants this event — active ones only, since
     * {@see WebhookEndpoint::subscribesTo()} answers no for the rest.
     *
     * @return list<WebhookEndpoint>
     */
    public function subscribedTo(string $event): array;
}
