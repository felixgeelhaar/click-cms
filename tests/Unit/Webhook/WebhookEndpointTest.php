<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Domain\Webhook\RetryPolicy;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Click\Cms\Domain\Webhook\WebhookEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WebhookEndpointTest extends TestCase
{
    private function endpoint(array $events, bool $active = true): WebhookEndpoint
    {
        return new WebhookEndpoint('ep_1', 'https://example.com/hooks', 'whsec_x', $events, $active);
    }

    public function testItSubscribesToAnEventItNames(): void
    {
        $this->assertTrue($this->endpoint(['content.published'])->subscribesTo('content.published'));
    }

    public function testItIgnoresAnEventItDoesNotName(): void
    {
        $this->assertFalse($this->endpoint(['content.published'])->subscribesTo('content.deleted'));
    }

    /**
     * A prefix subscription, so a front end that rebuilds on any content change
     * writes `content.*` rather than enumerating five event names and missing
     * the sixth when it is added.
     */
    public function testAPrefixWildcardMatchesTheFamily(): void
    {
        $endpoint = $this->endpoint(['content.*']);

        $this->assertTrue($endpoint->subscribesTo('content.published'));
        $this->assertTrue($endpoint->subscribesTo('content.deleted'));
        $this->assertFalse($endpoint->subscribesTo('auth.login_failed'));
    }

    public function testABareWildcardMatchesEverything(): void
    {
        $endpoint = $this->endpoint(['*']);

        $this->assertTrue($endpoint->subscribesTo('content.published'));
        $this->assertTrue($endpoint->subscribesTo('anything.at.all'));
    }

    /**
     * A deactivated endpoint subscribes to nothing. Asked here rather than at
     * every call site, because "is it active" is precisely the check a caller
     * forgets — and a forgotten check means deliveries queue up for an endpoint
     * an administrator switched off, and all fire at once if it is switched
     * back on.
     */
    public function testADeactivatedEndpointSubscribesToNothing(): void
    {
        $this->assertFalse($this->endpoint(['*'], active: false)->subscribesTo('content.published'));
    }

    public function testAnEndpointWithNoEventsSubscribesToNothing(): void
    {
        $this->assertFalse($this->endpoint([])->subscribesTo('content.published'));
    }

    public function testItRoundTripsThroughItsArrayForm(): void
    {
        $endpoint = $this->endpoint(['content.*']);

        $restored = WebhookEndpoint::fromArray($endpoint->toArray());

        $this->assertSame($endpoint->id, $restored->id);
        $this->assertSame($endpoint->url, $restored->url);
        $this->assertSame($endpoint->secret, $restored->secret);
        $this->assertSame($endpoint->events, $restored->events);
    }

    /**
     * The secret is what authenticates every delivery. An endpoint list that
     * could be rendered straight into a response would leak it, so the shape
     * meant for a reader has to be a different method from the shape meant for
     * disk — and the difference has to be tested, because it is invisible.
     */
    public function testThePublicShapeWithholdsTheSecret(): void
    {
        $public = $this->endpoint(['*'])->toPublicArray();

        $this->assertArrayNotHasKey('secret', $public);
        $this->assertSame('ep_1', $public['id']);
    }
}
