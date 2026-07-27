<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Application\Webhook\WebhookSender;
use Click\Cms\Domain\Webhook\HttpTransport;
use Click\Cms\Domain\Webhook\RetryPolicy;
use Click\Cms\Domain\Webhook\TransportResult;
use Click\Cms\Domain\Webhook\WebhookDelivery;
use Click\Cms\Domain\Webhook\WebhookEndpoint;
use Click\Cms\Domain\Webhook\WebhookSignature;
use Click\Cms\Infrastructure\Webhook\FileDeliveryQueue;
use Click\Cms\Infrastructure\Webhook\FileEndpointRepository;
use PHPUnit\Framework\TestCase;

/**
 * The sweep that actually delivers.
 *
 * Every rule worth having here is about what happens when the far end misbehaves
 * — refuses, hangs, answers 500, disappears — because the happy path is one POST
 * and the rest of the design exists for the unhappy ones.
 */
final class WebhookSenderTest extends TestCase
{
    private string $root;
    private FileEndpointRepository $endpoints;
    private FileDeliveryQueue $queue;
    private RecordingTransport $transport;

    private const NOW = 1785312000;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-webhooks-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/queue', 0o775, true);

        $this->endpoints = new FileEndpointRepository($this->root);
        $this->queue = new FileDeliveryQueue($this->root . '/queue');
        $this->transport = new RecordingTransport();

        $this->endpoints->save(new WebhookEndpoint(
            'ep_1',
            'https://example.com/hooks',
            'whsec_test',
            ['content.*'],
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/queue/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($this->root . '/endpoints.json');
        @rmdir($this->root . '/queue');
        @rmdir($this->root);
    }

    private function sender(): WebhookSender
    {
        return new WebhookSender(
            $this->queue,
            $this->endpoints,
            $this->transport,
            RetryPolicy::standard(),
        );
    }

    private function enqueue(string $id = 'dl_1', string $endpointId = 'ep_1'): void
    {
        $this->queue->push(WebhookDelivery::queued(
            $id,
            $endpointId,
            'content.published',
            ['key' => 'page:en:home'],
            self::NOW,
        ));
    }

    /* ---------------------------------------------------------- happy -- */

    public function testADueDeliveryIsSent(): void
    {
        $this->enqueue();

        $report = $this->sender()->sweep(self::NOW);

        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('https://example.com/hooks', $this->transport->calls[0]['url']);
        $this->assertSame(1, $report->deliveredCount());
    }

    public function testTheBodyCarriesTheEventAndPayload(): void
    {
        $this->enqueue();

        $this->sender()->sweep(self::NOW);

        $body = json_decode($this->transport->calls[0]['body'], true);

        $this->assertSame('content.published', $body['event']);
        $this->assertSame('page:en:home', $body['data']['key']);
        $this->assertSame('dl_1', $body['id']);
    }

    /**
     * The signature has to verify against the body exactly as sent. Signing a
     * re-encoded copy is the classic way this breaks: the receiver verifies
     * against the bytes it received, and any difference in key order or escaping
     * makes every delivery fail authentication for reasons nobody can see.
     */
    public function testTheSignatureVerifiesAgainstTheBodyAsSent(): void
    {
        $this->enqueue();

        $this->sender()->sweep(self::NOW);

        $call = $this->transport->calls[0];

        $this->assertTrue(WebhookSignature::verify(
            $call['body'],
            $call['headers']['X-Click-Signature'],
            'whsec_test',
            self::NOW,
        ));
    }

    public function testTheEventNameIsAlsoAHeaderSoAReceiverCanRouteWithoutParsing(): void
    {
        $this->enqueue();

        $this->sender()->sweep(self::NOW);

        $this->assertSame('content.published', $this->transport->calls[0]['headers']['X-Click-Event']);
    }

    public function testADeliveredRowIsNotSentAgain(): void
    {
        $this->enqueue();

        $sender = $this->sender();
        $sender->sweep(self::NOW);
        $sender->sweep(self::NOW + 600);

        $this->assertCount(1, $this->transport->calls);
    }

    /* --------------------------------------------------------- failure -- */

    public function testAFailedDeliveryIsRetriedLater(): void
    {
        $this->enqueue();
        $this->transport->result = TransportResult::failed('connection refused');

        $report = $this->sender()->sweep(self::NOW);

        $this->assertSame(1, $report->failedCount());

        // Not due immediately: the backoff has to actually hold it back.
        $this->transport->calls = [];
        $this->sender()->sweep(self::NOW + 30);
        $this->assertCount(0, $this->transport->calls);

        $this->sender()->sweep(self::NOW + 61);
        $this->assertCount(1, $this->transport->calls);
    }

    public function testAServerErrorIsAFailure(): void
    {
        $this->enqueue();
        $this->transport->result = TransportResult::responded(500);

        $this->assertSame(1, $this->sender()->sweep(self::NOW)->failedCount());
    }

    /**
     * A 3xx is not a success. The transport does not follow redirects — the URL
     * passed the SSRF policy and a redirect target has not — so a receiver
     * answering 302 is pointing us somewhere we have not agreed to go, and the
     * honest reading is that the delivery did not happen.
     */
    public function testARedirectIsAFailureRatherThanASuccess(): void
    {
        $this->enqueue();
        $this->transport->result = TransportResult::responded(302);

        $this->assertSame(1, $this->sender()->sweep(self::NOW)->failedCount());
    }

    public function testItGivesUpAfterTheRetriesAreExhausted(): void
    {
        $this->enqueue();
        $this->transport->result = TransportResult::failed('nope');

        $sender = new WebhookSender(
            $this->queue,
            $this->endpoints,
            $this->transport,
            RetryPolicy::of(2, 60, 3600),
        );

        $sender->sweep(self::NOW);
        $sender->sweep(self::NOW + 3600);

        $this->transport->calls = [];
        $sender->sweep(self::NOW + 100000);

        $this->assertCount(0, $this->transport->calls);
    }

    /* ------------------------------------------------------- orphaned -- */

    /**
     * An endpoint deleted while its deliveries were still queued leaves rows
     * pointing at nothing. They can never succeed, so they are dropped rather
     * than retried — and counted, so a sweep that quietly discards work still
     * says it did.
     */
    public function testADeliveryForAVanishedEndpointIsDroppedNotRetried(): void
    {
        $this->enqueue('dl_1', 'ep_gone');

        $report = $this->sender()->sweep(self::NOW);

        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(1, $report->orphanedCount());

        $this->sender()->sweep(self::NOW + 100000);
        $this->assertCount(0, $this->transport->calls);
    }

    /**
     * An endpoint switched off keeps its queued deliveries but sends none of
     * them, and they are dropped rather than accumulating — otherwise switching
     * an endpoint back on a month later fires a month of history at it at once.
     */
    public function testADeliveryForADeactivatedEndpointIsDropped(): void
    {
        $this->endpoints->save(new WebhookEndpoint(
            'ep_1',
            'https://example.com/hooks',
            'whsec_test',
            ['content.*'],
            active: false,
        ));
        $this->enqueue();

        $report = $this->sender()->sweep(self::NOW);

        $this->assertCount(0, $this->transport->calls);
        $this->assertSame(1, $report->orphanedCount());
    }

    /* --------------------------------------------------------- limits -- */

    public function testASweepTakesNoMoreThanItsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->enqueue("dl_{$i}");
        }

        $this->sender()->sweep(self::NOW, 2);

        $this->assertCount(2, $this->transport->calls);
    }

    /**
     * One receiver that throws must not strand the rest of the queue. Unattended
     * work that stops halfway is indistinguishable from unattended work that
     * never ran.
     */
    public function testATransportThatThrowsIsTreatedAsAFailedAttempt(): void
    {
        $this->enqueue();
        $this->transport->throw = true;

        $report = $this->sender()->sweep(self::NOW);

        $this->assertSame(1, $report->failedCount());
    }
}

/** A transport that records what it was asked to send and answers to order. */
final class RecordingTransport implements HttpTransport
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $calls = [];

    public ?TransportResult $result = null;
    public bool $throw = false;

    public function post(string $url, string $body, array $headers, int $timeoutSeconds): TransportResult
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->throw) {
            throw new \RuntimeException('the transport exploded');
        }

        return $this->result ?? TransportResult::responded(200);
    }
}
