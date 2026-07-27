<?php

declare(strict_types=1);

namespace Click\Cms\Application\Webhook;

use Click\Cms\Domain\Webhook\DeliveryQueue;
use Click\Cms\Domain\Webhook\EndpointRepository;
use Click\Cms\Domain\Webhook\HttpTransport;
use Click\Cms\Domain\Webhook\RetryPolicy;
use Click\Cms\Domain\Webhook\TransportResult;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Click\Cms\Domain\Webhook\WebhookSignature;
use Throwable;

/**
 * Sends whatever is due, and decides what to do about whatever did not land.
 *
 * The sweep half of the queue: {@see WebhookDispatcher} puts events in during a
 * request, this takes them out from cron. Everything interesting here concerns
 * a receiver misbehaving, because the happy path is one POST and the rest of the
 * design exists for the other cases.
 */
final class WebhookSender
{
    /** How long one delivery may take before it counts as failed. */
    private const TIMEOUT_SECONDS = 10;

    /** The most to attempt in one sweep, so a backlog cannot become a run that never ends. */
    public const DEFAULT_LIMIT = 100;

    public function __construct(
        private readonly DeliveryQueue $queue,
        private readonly EndpointRepository $endpoints,
        private readonly HttpTransport $transport,
        private readonly RetryPolicy $policy,
    ) {}

    public function sweep(int $now, int $limit = self::DEFAULT_LIMIT): SendReport
    {
        $outcomes = [];

        foreach ($this->queue->due($now, $limit) as $delivery) {
            $outcomes[] = $this->deliver($delivery, $now);
        }

        return new SendReport($outcomes);
    }

    /**
     * @return array<string, mixed>
     */
    private function deliver(WebhookDelivery $delivery, int $now): array
    {
        $endpoint = $this->endpoints->find($delivery->endpointId);

        // No endpoint, or one switched off. Either way this delivery can never
        // land, so it is dropped rather than retried — and reported, because a
        // sweep that silently discards work is the quiet failure `core.md`
        // exists to prevent.
        //
        // Dropping rather than holding matters for the deactivated case
        // especially: holding would mean switching an endpoint back on a month
        // later fires a month of accumulated history at it in one burst.
        if ($endpoint === null || !$endpoint->subscribesTo($delivery->event)) {
            $this->queue->update($delivery->failed(
                $endpoint === null
                    ? 'The endpoint no longer exists.'
                    : 'The endpoint is switched off or no longer subscribes to this event.',
                // A policy of one attempt, so this is terminal immediately
                // rather than after six more sweeps that will each reach this
                // same branch.
                RetryPolicy::of(1, 1, 1),
                $now,
                null,
            ));

            return [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'result' => 'orphaned',
                'reason' => 'No active endpoint subscribes to this any more.',
            ];
        }

        $body = $this->body($delivery, $now);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'click-cms-webhooks/1',
            // The signature covers the body exactly as sent. Built from the same
            // `$body` string that is handed to the transport, never from a
            // re-encode — a receiver verifies against the bytes it received, and
            // any difference in key order or escaping fails every delivery for a
            // reason nobody can see from either end.
            'X-Click-Signature' => WebhookSignature::sign($body, $endpoint->secret, $now),
            // So a receiver can route without parsing the body, and reject an
            // event it does not handle before spending anything on it.
            'X-Click-Event' => $delivery->event,
            'X-Click-Delivery' => $delivery->id,
        ];

        try {
            $result = $this->transport->post($endpoint->url, $body, $headers, self::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            // A transport that throws is a failed attempt, not a failed sweep.
            // One broken receiver must not strand every delivery behind it.
            $result = TransportResult::failed($e->getMessage());
        }

        if ($result->succeeded) {
            $this->queue->update($delivery->succeeded($now, $result->status ?? 200));

            return [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'result' => 'delivered',
                'status' => $result->status,
            ];
        }

        $updated = $delivery->failed($result->error ?? 'unknown', $this->policy, $now, $result->status);
        $this->queue->update($updated);

        return [
            'id' => $delivery->id,
            'event' => $delivery->event,
            'result' => 'failed',
            'status' => $result->status,
            'reason' => $result->error,
            'attempts' => $updated->attempts,
            'givingUp' => $updated->status === WebhookDelivery::FAILED,
        ];
    }

    /**
     * The JSON a receiver gets.
     *
     * `id` is the delivery, not the event: a receiver that has already processed
     * an id can discard a repeat. That matters because this queue is
     * at-least-once — a delivery that succeeds but whose status write fails is
     * sent again — and saying so in the payload is cheaper than pretending
     * otherwise.
     */
    private function body(WebhookDelivery $delivery, int $now): string
    {
        return json_encode([
            'id' => $delivery->id,
            'event' => $delivery->event,
            'sentAt' => gmdate('c', $now),
            'occurredAt' => gmdate('c', $delivery->createdAt),
            'attempt' => $delivery->attempts + 1,
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
