<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Identity;

use Click\Cms\Domain\Identity\TwoFactorEnrolment;
use PHPUnit\Framework\TestCase;

final class TwoFactorEnrolmentTest extends TestCase
{
    private function hashes(string ...$codes): array
    {
        return array_map(TwoFactorEnrolment::hashRecoveryCode(...), $codes);
    }

    public function testAnAccountWithNoEnrolmentIsNotProtected(): void
    {
        $this->assertFalse(TwoFactorEnrolment::none()->isActive());
        $this->assertFalse(TwoFactorEnrolment::none()->isPending());
    }

    /**
     * The distinction the whole class exists for. A secret exists from the
     * moment the QR code is shown, and must not protect anything until the
     * person has proved they can produce a code from it — otherwise turning
     * two-factor on locks you out at the exact moment you have not finished
     * setting it up.
     */
    public function testASecretAloneDoesNotProtectTheAccount(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', []);

        $this->assertTrue($enrolment->isPending());
        $this->assertFalse($enrolment->isActive());
    }

    public function testConfirmingMakesItActive(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', [])->confirmed('2026-07-27T09:00:00+00:00');

        $this->assertTrue($enrolment->isActive());
        $this->assertFalse($enrolment->isPending());
    }

    /* ------------------------------------------------------- recovery -- */

    public function testAValidRecoveryCodeIsAccepted(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb', 'cccc-dddd'))
            ->confirmed('2026-07-27T09:00:00+00:00');

        $this->assertNotNull($enrolment->withoutRecoveryCode('aaaa-bbbb'));
    }

    /**
     * Single use is the entire property. A recovery code that still worked
     * afterwards would be a password with no rotation, written on a piece of
     * paper somebody keeps in a drawer.
     */
    public function testARecoveryCodeIsSpentWhenUsed(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb', 'cccc-dddd'))
            ->confirmed('2026-07-27T09:00:00+00:00');

        $after = $enrolment->withoutRecoveryCode('aaaa-bbbb');

        $this->assertSame(1, $after->unusedRecoveryCodeCount());
        $this->assertNull($after->withoutRecoveryCode('aaaa-bbbb'));
    }

    public function testTheOtherCodesSurvive(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb', 'cccc-dddd'))
            ->confirmed('2026-07-27T09:00:00+00:00');

        $after = $enrolment->withoutRecoveryCode('aaaa-bbbb');

        $this->assertNotNull($after->withoutRecoveryCode('cccc-dddd'));
    }

    /**
     * People retype these from a printout. The dash they were shown with and
     * the case they happen to use are not part of the secret.
     */
    public function testACodeTypedBackDifferentlyStillMatches(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb'));

        $this->assertNotNull($enrolment->withoutRecoveryCode('AAAA BBBB'));
        $this->assertNotNull($enrolment->withoutRecoveryCode('aaaabbbb'));
    }

    public function testAnUnknownRecoveryCodeIsRefused(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb'));

        $this->assertNull($enrolment->withoutRecoveryCode('not-a-code'));
    }

    public function testRecoveryCodesAreStoredHashedNotInClear(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb'));

        $stored = json_encode($enrolment->toArray());

        $this->assertStringNotContainsString('aaaa-bbbb', (string) $stored);
    }

    /* ---------------------------------------------------------- shape -- */

    public function testItRoundTripsThroughItsArrayForm(): void
    {
        $enrolment = TwoFactorEnrolment::pending('SECRET', $this->hashes('aaaa-bbbb'))
            ->confirmed('2026-07-27T09:00:00+00:00');

        $restored = TwoFactorEnrolment::fromArray($enrolment->toArray());

        $this->assertTrue($restored->isActive());
        $this->assertSame('SECRET', $restored->secret);
        $this->assertSame(1, $restored->unusedRecoveryCodeCount());
    }

    public function testAMissingBlockReadsAsNoEnrolment(): void
    {
        $this->assertFalse(TwoFactorEnrolment::fromArray(null)->isActive());
        $this->assertFalse(TwoFactorEnrolment::fromArray([])->isActive());
    }

    /**
     * A document hand-edited to hold a confirmation date but no secret must not
     * read as protected: there would be nothing to verify against, so every
     * sign-in would be refused with no way back in.
     */
    public function testAConfirmationWithNoSecretIsNotActive(): void
    {
        $this->assertFalse(
            TwoFactorEnrolment::fromArray(['confirmedAt' => '2026-07-27T09:00:00+00:00'])->isActive()
        );
    }
}
