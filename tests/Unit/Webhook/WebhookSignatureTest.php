<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Domain\Webhook\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * How a receiver knows a delivery came from this CMS.
 *
 * The threat is not eavesdropping — https covers that — but forgery: a webhook
 * endpoint is a URL that accepts POSTs, usually from anywhere, and usually
 * triggers something expensive like a site rebuild. Without a signature anyone
 * who learns the URL can drive it.
 *
 * The scheme is the one Stripe and GitHub converged on, and it is worth copying
 * rather than inventing: HMAC-SHA256 over a timestamp and the exact body,
 * with the timestamp inside the signed material so a captured delivery cannot
 * be replayed for ever.
 */
final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdef';
    private const BODY = '{"event":"content.published","key":"page:en:home"}';

    public function testItProducesAStableSignatureForTheSameInputs(): void
    {
        $a = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);
        $b = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertSame($a, $b);
    }

    public function testTheHeaderCarriesBothTheTimestampAndTheDigest(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertStringContainsString('t=1785312000', $header);
        $this->assertStringContainsString('v1=', $header);
    }

    /**
     * The property the whole scheme rests on. If the body could change without
     * the signature changing, the signature would be authenticating the
     * timestamp and nothing else.
     */
    public function testChangingTheBodyChangesTheSignature(): void
    {
        $a = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);
        $b = WebhookSignature::sign(self::BODY . ' ', self::SECRET, 1785312000);

        $this->assertNotSame($a, $b);
    }

    public function testChangingTheSecretChangesTheSignature(): void
    {
        $a = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);
        $b = WebhookSignature::sign(self::BODY, 'whsec_something_else', 1785312000);

        $this->assertNotSame($a, $b);
    }

    /**
     * The replay defence. The same body signed a minute later is a different
     * signature, so a receiver that checks the age of `t` can refuse a delivery
     * somebody captured and re-sent.
     */
    public function testChangingTheTimestampChangesTheSignature(): void
    {
        $a = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);
        $b = WebhookSignature::sign(self::BODY, self::SECRET, 1785312060);

        $this->assertNotSame($a, $b);
    }

    /* -------------------------------------------------------- verifying -- */

    public function testAGenuineSignatureVerifies(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, 1785312000));
    }

    public function testATamperedBodyDoesNotVerify(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertFalse(WebhookSignature::verify('{"event":"nonsense"}', $header, self::SECRET, 1785312000));
    }

    public function testTheWrongSecretDoesNotVerify(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertFalse(WebhookSignature::verify(self::BODY, $header, 'whsec_wrong', 1785312000));
    }

    /**
     * A delivery older than the tolerance is refused even though its digest is
     * genuine — that is the entire point of putting the timestamp in the signed
     * material rather than merely alongside it.
     */
    public function testAnOldDeliveryDoesNotVerify(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertFalse(
            WebhookSignature::verify(self::BODY, $header, self::SECRET, 1785312000 + 3600)
        );
    }

    public function testADeliveryInsideTheToleranceVerifies(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertTrue(
            WebhookSignature::verify(self::BODY, $header, self::SECRET, 1785312000 + 60)
        );
    }

    /**
     * A clock a little ahead of ours must not make every delivery fail. The
     * tolerance is symmetric for that reason.
     */
    public function testASmallClockSkewInEitherDirectionIsTolerated(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1785312000);

        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, 1785312000 - 60));
    }

    public function testRubbishInTheHeaderDoesNotVerifyAndDoesNotThrow(): void
    {
        foreach (['', 'nonsense', 't=', 'v1=abc', 't=abc,v1=def', 't=1785312000'] as $header) {
            $this->assertFalse(
                WebhookSignature::verify(self::BODY, $header, self::SECRET, 1785312000),
                "'{$header}' should not verify"
            );
        }
    }

    /* ----------------------------------------------------------- secrets -- */

    public function testAGeneratedSecretIsLongAndUnpredictable(): void
    {
        $a = WebhookSignature::generateSecret();
        $b = WebhookSignature::generateSecret();

        $this->assertNotSame($a, $b);
        $this->assertGreaterThanOrEqual(32, strlen($a));
        $this->assertStringStartsWith('whsec_', $a);
    }
}
