<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Domain\Webhook\RetryPolicy;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Click\Cms\Domain\Webhook\WebhookEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testTheFirstFailureIsRetriedSoon(): void
    {
        $this->assertSame(60, RetryPolicy::standard()->delayAfter(1));
    }

    /**
     * Exponential, so a receiver that is down for an hour is knocked on a
     * handful of times rather than sixty. A fixed interval turns every outage
     * into a self-inflicted flood arriving exactly when the receiver is least
     * able to cope.
     */
    public function testTheDelayGrowsWithEachAttempt(): void
    {
        $policy = RetryPolicy::standard();

        $this->assertSame(60, $policy->delayAfter(1));
        $this->assertSame(120, $policy->delayAfter(2));
        $this->assertSame(240, $policy->delayAfter(3));
        $this->assertSame(480, $policy->delayAfter(4));
    }

    /**
     * Capped, so the gap does not grow to days. A receiver fixed on Monday
     * morning should not wait until Wednesday for its next delivery.
     */
    public function testTheDelayIsCapped(): void
    {
        $policy = RetryPolicy::standard();

        $this->assertSame(3600, $policy->delayAfter(20));
    }

    public function testItGivesUpEventually(): void
    {
        $policy = RetryPolicy::standard();

        $this->assertFalse($policy->isExhausted(1));
        $this->assertTrue($policy->isExhausted($policy->maxAttempts));
    }

    public function testARetryCountBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RetryPolicy::of(0, 60, 3600);
    }
}
