<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Identity;

use Click\Cms\Domain\Identity\Base32;
use Click\Cms\Domain\Identity\Totp;
use PHPUnit\Framework\TestCase;

/**
 * The time-based one-time password, hand-rolled because a Composer dependency
 * is not available to this project.
 *
 * Hand-rolled crypto is normally a bad idea and this is the exception that
 * proves the rule: RFC 6238 is HMAC-SHA1 over a counter, the standard publishes
 * test vectors, and every authenticator app in existence implements the same
 * thing. The risk of getting it wrong is real but it is *detectable* — a wrong
 * implementation does not produce subtly weak codes, it produces codes that no
 * app agrees with, which the vectors below catch immediately.
 */
final class TotpTest extends TestCase
{
    /**
     * RFC 6238 appendix B, the SHA-1 rows. The secret is the ASCII string
     * "12345678901234567890" the RFC specifies.
     */
    private const RFC_SECRET = '12345678901234567890';

    public function testItMatchesTheRfc6238Vectors(): void
    {
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame(
                $expected,
                Totp::codeAt(self::RFC_SECRET, $time),
                "RFC 6238 vector at t={$time}"
            );
        }
    }

    public function testACodeIsSixDigitsAndKeepsItsLeadingZeros(): void
    {
        $code = Totp::codeAt(self::RFC_SECRET, 1234567890);

        $this->assertSame(6, strlen($code));
        $this->assertSame('005924', $code);
    }

    public function testTheCodeChangesEveryThirtySeconds(): void
    {
        $a = Totp::codeAt(self::RFC_SECRET, 1785312000);
        $b = Totp::codeAt(self::RFC_SECRET, 1785312029);
        $c = Totp::codeAt(self::RFC_SECRET, 1785312030);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    /* -------------------------------------------------------- verifying -- */

    public function testTheCurrentCodeVerifies(): void
    {
        $now = 1785312000;

        $this->assertTrue(Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $now), $now));
    }

    /**
     * A phone's clock drifts, and a person takes a few seconds to type. One step
     * either way is the usual tolerance; more would widen the window an attacker
     * has to guess in for no real gain in usability.
     */
    public function testOneStepOfDriftIsTolerated(): void
    {
        $now = 1785312000;

        $this->assertTrue(Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $now - 30), $now));
        $this->assertTrue(Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $now + 30), $now));
    }

    public function testFurtherDriftIsNotTolerated(): void
    {
        $now = 1785312000;

        $this->assertFalse(Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $now - 90), $now));
        $this->assertFalse(Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $now + 90), $now));
    }

    public function testAWrongCodeDoesNotVerify(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '000000', 1785312000));
    }

    public function testRubbishDoesNotVerifyAndDoesNotThrow(): void
    {
        foreach (['', 'abcdef', '12345', '1234567', '12 34 56'] as $code) {
            $this->assertFalse(Totp::verify(self::RFC_SECRET, $code, 1785312000), "'{$code}'");
        }
    }

    /**
     * People read these off a screen and often type the space they see.
     */
    public function testASpaceInTheTypedCodeIsIgnored(): void
    {
        $now = 1785312000;
        $code = Totp::codeAt(self::RFC_SECRET, $now);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        $this->assertTrue(Totp::verify(self::RFC_SECRET, $spaced, $now));
    }

    /* ---------------------------------------------------------- secrets -- */

    public function testAGeneratedSecretIsBase32AndLongEnough(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNotNull(Base32::decode($secret));
        // 160 bits, the size RFC 4226 recommends and every app expects.
        $this->assertSame(20, strlen((string) Base32::decode($secret)));
    }

    public function testTwoSecretsDiffer(): void
    {
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    /* ------------------------------------------------------------- uri -- */

    /**
     * The string behind the QR code. Getting the shape wrong means the app
     * enrols something subtly different — the wrong issuer, or worse a secret it
     * reads as raw bytes — and every code is then rejected.
     */
    public function testTheEnrolmentUriCarriesWhatAnAppNeeds(): void
    {
        $uri = Totp::enrolmentUri('JBSWY3DPEHPK3PXP', 'jo@example.com', 'Example Site');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Example%20Site', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    /**
     * The label is `Issuer:account`, and both halves are escaped. An issuer
     * containing a colon or a slash would otherwise change what the app thinks
     * the account is called.
     */
    public function testTheLabelIsEscaped(): void
    {
        $uri = Totp::enrolmentUri('JBSWY3DPEHPK3PXP', 'jo@example.com', 'Acme: Sites/EU');

        $this->assertStringNotContainsString('Acme: Sites/EU', $uri);
        $this->assertStringContainsString('jo%40example.com', $uri);
    }
}
