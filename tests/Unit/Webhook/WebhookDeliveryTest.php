<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Domain\Webhook\RetryPolicy;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Click\Cms\Domain\Webhook\WebhookEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WebhookDeliveryTest extends TestCase
{
    private function pending(): WebhookDelivery
    {
        return WebhookDelivery::queued('dl_1', 'ep_1', 'content.published', ['key' => 'page:en:home'], 1785312000);
    }

    public function testAQueuedDeliveryIsDueImmediately(): void
    {
        $this->assertSame(1785312000, $this->pending()->nextAttemptAt);
        $this->assertSame(WebhookDelivery::PENDING, $this->pending()->status);
    }

    public function testSuccessMarksItDeliveredAndStopsIt(): void
    {
        $done = $this->pending()->succeeded(1785312005, 200);

        $this->assertSame(WebhookDelivery::DELIVERED, $done->status);
        $this->assertSame(200, $done->lastStatus);
        $this->assertFalse($done->isDueAt(1785312999));
    }

    public function testAFailureSchedulesARetry(): void
    {
        $failed = $this->pending()->failed('connection refused', RetryPolicy::standard(), 1785312005, null);

        $this->assertSame(WebhookDelivery::PENDING, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertSame(1785312005 + 60, $failed->nextAttemptAt);
        $this->assertSame('connection refused', $failed->lastError);
    }

    public function testTheRetryIsNotDueBeforeItsTime(): void
    {
        $failed = $this->pending()->failed('nope', RetryPolicy::standard(), 1785312005, null);

        $this->assertFalse($failed->isDueAt(1785312005 + 59));
        $this->assertTrue($failed->isDueAt(1785312005 + 60));
    }

    /**
     * A delivery that has exhausted its retries is not retried for ever. The
     * queue would otherwise grow without bound against an endpoint whose owner
     * has forgotten it exists, and every sweep would spend its time on
     * deliveries that will never land.
     */
    public function testItStopsAfterTheLastAttempt(): void
    {
        $delivery = $this->pending();
        $policy = RetryPolicy::of(3, 60, 3600);

        for ($i = 0; $i < 3; $i++) {
            $delivery = $delivery->failed('nope', $policy, 1785312005, 500);
        }

        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertFalse($delivery->isDueAt(PHP_INT_MAX));
    }

    public function testItRoundTripsThroughItsArrayForm(): void
    {
        $delivery = $this->pending()->failed('nope', RetryPolicy::standard(), 1785312005, 503);

        $restored = WebhookDelivery::fromArray($delivery->toArray());

        $this->assertSame($delivery->id, $restored->id);
        $this->assertSame($delivery->attempts, $restored->attempts);
        $this->assertSame($delivery->nextAttemptAt, $restored->nextAttemptAt);
        $this->assertSame($delivery->status, $restored->status);
        $this->assertSame($delivery->payload, $restored->payload);
        $this->assertSame(503, $restored->lastStatus);
    }
}
