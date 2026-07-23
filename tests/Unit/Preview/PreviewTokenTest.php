<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Preview;

use Click\Cms\Domain\Preview\PreviewToken;
use PHPUnit\Framework\TestCase;

final class PreviewTokenTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-comfortably-long-enough';

    public function testATokenItIssuedIsAccepted(): void
    {
        $token = PreviewToken::issue(self::SECRET, 'pricing');

        $this->assertTrue(PreviewToken::accepts(self::SECRET, 'pricing', $token));
    }

    /**
     * The whole point of signing: nothing short of holding the secret produces
     * a working link.
     */
    public function testAnInventedTokenIsRefused(): void
    {
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', str_repeat('a', 64)));
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', (time() + 600) . '.' . str_repeat('f', 64)));
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', 'nonsense'));
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', ''));
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', null));
    }

    public function testATamperedSignatureIsRefused(): void
    {
        $token = PreviewToken::issue(self::SECRET, 'pricing');
        [$expiry, $signature] = explode('.', $token, 2);

        // Flip one character of the signature.
        $signature[0] = $signature[0] === 'a' ? 'b' : 'a';

        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', $expiry . '.' . $signature));
    }

    /**
     * A link for one unfinished page must not open another. The slug is signed
     * even though it is not carried in the token, which is what makes this hold.
     */
    public function testATokenDoesNotOpenADifferentPage(): void
    {
        $token = PreviewToken::issue(self::SECRET, 'pricing');

        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'unannounced-product', $token));
    }

    public function testTheExpiryCannotBeExtendedByEditingIt(): void
    {
        $token = PreviewToken::issue(self::SECRET, 'pricing', 60);
        [, $signature] = explode('.', $token, 2);

        $forged = (time() + 86400) . '.' . $signature;

        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', $forged));
    }

    public function testATokenStopsWorkingWhenItExpires(): void
    {
        $now = 1_700_000_000;
        $token = PreviewToken::issue(self::SECRET, 'pricing', 600, $now);

        $this->assertTrue(PreviewToken::accepts(self::SECRET, 'pricing', $token, $now + 599));
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', $token, $now + 600));
        $this->assertFalse(PreviewToken::accepts(self::SECRET, 'pricing', $token, $now + 601));
    }

    /**
     * Nothing about an issued token is stored, so there is no way to withdraw
     * one. The cap is the only bound on how long a leaked link keeps working.
     */
    public function testNoTokenOutlivesTheCapHoweverLongIsAskedFor(): void
    {
        $now = 1_700_000_000;
        $token = PreviewToken::issue(self::SECRET, 'pricing', 10 * 365 * 86400, $now);

        $this->assertSame(
            $now + PreviewToken::MAX_TTL_SECONDS,
            (int) explode('.', $token, 2)[0]
        );
    }

    public function testADifferentSecretDoesNotVerify(): void
    {
        $token = PreviewToken::issue(self::SECRET, 'pricing');

        $this->assertFalse(PreviewToken::accepts('some-other-secret-entirely', 'pricing', $token));
    }

    /**
     * An unreadable or missing secret must refuse everything rather than accept
     * everything. Getting this backwards would open every unpublished page.
     */
    public function testAnEmptySecretAcceptsNothing(): void
    {
        $this->assertFalse(PreviewToken::accepts('', 'pricing', PreviewToken::issue('', 'pricing')));
    }

    public function testExpiryIsReadableOnlyFromATokenThatVerifies(): void
    {
        $now = 1_700_000_000;
        $token = PreviewToken::issue(self::SECRET, 'pricing', 600, $now);

        $this->assertSame($now + 600, PreviewToken::expiresAt(self::SECRET, 'pricing', $token, $now));
        $this->assertNull(PreviewToken::expiresAt(self::SECRET, 'other', $token, $now));
        $this->assertNull(PreviewToken::expiresAt(self::SECRET, 'pricing', 'made-up', $now));
    }

    /**
     * Two pages issued in the same second must not share a signature, or a link
     * to one would open the other.
     */
    public function testDifferentPagesProduceDifferentSignatures(): void
    {
        $now = 1_700_000_000;

        $this->assertNotSame(
            PreviewToken::issue(self::SECRET, 'a', 600, $now),
            PreviewToken::issue(self::SECRET, 'b', 600, $now)
        );
    }
}
