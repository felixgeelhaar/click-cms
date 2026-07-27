<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * The queue of events waiting to be delivered.
 */
interface DeliveryQueue
{
    public function push(WebhookDelivery $delivery): void;

    /**
     * Everything due at `$now`, oldest first.
     *
     * Oldest first so a receiver sees events in roughly the order they
     * happened. It cannot be a guarantee — a delivery that fails and backs off
     * is overtaken by later ones, and any ordering guarantee across retries
     * would mean stalling the whole queue behind one broken endpoint — so the
     * documented contract is that a receiver must tolerate reordering. This is
     * best effort, and the payload carries a timestamp for receivers that care.
     *
     * @param int $limit The most to take in one sweep, so a backlog of ten
     *        thousand does not become one cron run that never finishes.
     * @return list<WebhookDelivery>
     */
    public function due(int $now, int $limit): array;

    /** Record the outcome of an attempt. */
    public function update(WebhookDelivery $delivery): void;

    /**
     * Discard delivered and permanently failed rows older than a cutoff.
     *
     * The queue is the only thing here that grows without bound. A busy site
     * with one endpoint produces a row per save, and none of them are
     * interesting a week later — but they are interesting for a day or two,
     * which is why this is a retention sweep and not a delete-on-success.
     *
     * @return int How many were discarded.
     */
    public function prune(int $before): int;

    /**
     * @param ?string $status One of {@see WebhookDelivery}'s constants, or null
     *        for everything.
     * @return list<WebhookDelivery>
     */
    public function recent(int $limit, ?string $status = null): array;
}
