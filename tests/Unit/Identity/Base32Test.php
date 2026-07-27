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
final class Base32Test extends TestCase
{
    /**
     * RFC 4648 section 10. If the alphabet or the padding is wrong, an
     * authenticator app silently derives a different key from the same QR code
     * and every code it produces is rejected — with nothing anywhere saying why.
     */
    public function testItMatchesTheRfcVectors(): void
    {
        $this->assertSame('', Base32::encode(''));
        $this->assertSame('MY======', Base32::encode('f'));
        $this->assertSame('MZXQ====', Base32::encode('fo'));
        $this->assertSame('MZXW6===', Base32::encode('foo'));
        $this->assertSame('MZXW6YQ=', Base32::encode('foob'));
        $this->assertSame('MZXW6YTB', Base32::encode('fooba'));
        $this->assertSame('MZXW6YTBOI======', Base32::encode('foobar'));
    }

    public function testItDecodesItsOwnOutput(): void
    {
        foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', "\x00\xff\x10"] as $raw) {
            $this->assertSame($raw, Base32::decode(Base32::encode($raw)));
        }
    }

    /**
     * Users retype these from a screen. Lower case, spaces every four characters
     * and missing padding are all things a person produces, and refusing them
     * would be refusing a correct secret for being written the way people write.
     */
    public function testItToleratesHowPeopleTypeIt(): void
    {
        $this->assertSame('foobar', Base32::decode('mzxw6ytboi======'));
        $this->assertSame('foobar', Base32::decode('MZXW 6YTB OI'));
        $this->assertSame('foobar', Base32::decode('MZXW6YTBOI'));
    }

    public function testItRefusesCharactersOutsideTheAlphabet(): void
    {
        $this->assertNull(Base32::decode('MZXW6YTB01'));
        $this->assertNull(Base32::decode('not base32!'));
    }
}
