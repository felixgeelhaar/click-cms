<?php

declare(strict_types=1);

namespace Click\Cms\Application\Webhook;

use Click\Cms\Domain\Webhook\DeliveryQueue;
use Click\Cms\Domain\Webhook\EndpointRepository;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Throwable;

/**
 * Turns an event into queued deliveries, one per subscribed endpoint.
 *
 * The request-side half of the queue. Everything here runs inside somebody's
 * Save, so the whole design is about spending as little of their time as
 * possible and never, under any circumstances, failing their write.
 *
 * ## Why it queues rather than sends
 *
 * Sending here would put a remote host's latency on the Save button, hold a PHP
 * worker for the length of a timeout when a receiver hangs, and — worst — have
 * no way to retry, so a receiver that happened to be restarting would simply
 * never hear about the change. See {@see WebhookDelivery} for the longer form.
 *
 * ## Why nothing here throws
 *
 * A plugin that can break a save is a plugin that can take a site down. The
 * hooks this listens to are announcements after the fact — the write has already
 * landed — so there is nothing useful to be done with a failure except record
 * it. A full disk must cost the site its webhooks, not its content.
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly DeliveryQueue $queue,
        private readonly EndpointRepository $endpoints,
    ) {}

    /**
     * Queue this event for everyone listening.
     *
     * @param array<string, mixed> $payload
     * @return int How many deliveries were queued.
     */
    public function dispatch(string $event, array $payload, ?int $now = null): int
    {
        $now ??= time();
        $queued = 0;

        try {
            $subscribers = $this->endpoints->subscribedTo($event);
        } catch (Throwable $e) {
            error_log("click-cms webhooks: could not read the endpoint list: {$e->getMessage()}");

            return 0;
        }

        foreach ($subscribers as $endpoint) {
            try {
                $this->queue->push(WebhookDelivery::queued(
                    self::deliveryId(),
                    $endpoint->id,
                    $event,
                    $payload,
                    $now,
                ));
                $queued++;
            } catch (Throwable $e) {
                // One endpoint's delivery failing to queue must not stop the
                // others, and must not reach the caller: the content write it
                // is announcing has already happened.
                error_log("click-cms webhooks: could not queue {$event} for {$endpoint->id}: {$e->getMessage()}");
            }
        }

        return $queued;
    }

    /**
     * Random rather than sequential.
     *
     * A receiver is told to treat the id as an idempotency key, and a
     * predictable one would let anyone who saw a single delivery guess the
     * neighbouring ids — which, for a receiver that dedupes on them, is a way to
     * make it discard deliveries it has not had yet.
     */
    private static function deliveryId(): string
    {
        return bin2hex(random_bytes(12));
    }
}
