<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Authentication;

use Click\Cms\Application\Authentication\LoginSprayGuard;
use PHPUnit\Framework\TestCase;

/**
 * The ceiling on failed logins for the site as a whole.
 *
 * The per-username lockout cannot see a password spray: one common password
 * tried once each against a hundred accounts leaves every account two failures
 * short of its own threshold while the site is being worked through
 * methodically. This counter is what notices, so the properties that matter
 * here are that it adds up regardless of which account was named, that it
 * expires by time on its own, and that a failure to read its own state can
 * never wedge the login form shut for everyone.
 *
 * Time is passed in rather than waited for: every rule in here is about a
 * window, and a test suite that slept out fifteen-minute windows would be a
 * test suite nobody runs.
 */
final class LoginSprayGuardTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-spray-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->path = $this->dir . '/login-spray.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function guard(int $max = 5, int $window = 900): LoginSprayGuard
    {
        return new LoginSprayGuard($this->path, $max, $window);
    }

    /* ------------------------------------------------------------ counting -- */

    public function testASiteWithNoFailuresIsNotBlocked(): void
    {
        $this->assertFalse($this->guard()->isTripped(1_000_000));
        $this->assertNull($this->guard()->secondsRemaining(1_000_000));
    }

    public function testFailuresAddUpUntilTheThresholdIsReached(): void
    {
        $guard = $this->guard(5);

        for ($i = 0; $i < 4; $i++) {
            $guard->recordFailure(1_000_000 + $i);
        }
        $this->assertFalse($guard->isTripped(1_000_004), 'one short of the ceiling');

        $guard->recordFailure(1_000_004);
        $this->assertTrue($guard->isTripped(1_000_004), 'the ceiling is reached');
        $this->assertGreaterThan(0, $guard->secondsRemaining(1_000_004));
    }

    public function testTheCountSurvivesAFreshGuardOverTheSameFile(): void
    {
        // Each request builds its own guard, so a count that lived only in
        // memory would reset between the attempts it is meant to be adding up.
        for ($i = 0; $i < 3; $i++) {
            $this->guard(3)->recordFailure(1_000_000 + $i);
        }

        $this->assertTrue($this->guard(3)->isTripped(1_000_002));
    }

    /* -------------------------------------------------------------- expiry -- */

    /**
     * The refusal must be no longer-lived than the evidence for it. Nothing
     * records a deadline; the answer is recomputed from the failure times, so
     * a burst that stops is forgotten exactly one window later.
     */
    public function testTheBlockLiftsOnceTheFailuresAgeOut(): void
    {
        $guard = $this->guard(3, 900);
        $now = 1_000_000;

        for ($i = 0; $i < 3; $i++) {
            $guard->recordFailure($now);
        }

        $this->assertTrue($guard->isTripped($now), 'blocked immediately after');
        $this->assertTrue($guard->isTripped($now + 899), 'still blocked one second short of the window');
        $this->assertFalse($guard->isTripped($now + 900), 'lifted exactly on the window');
        $this->assertFalse($guard->isTripped($now + 100_000), 'and stays lifted');
    }

    public function testTheWaitReportedShrinksAsTheWindowRunsDown(): void
    {
        $guard = $this->guard(2, 900);
        $guard->recordFailure(1_000_000);
        $guard->recordFailure(1_000_000);

        $this->assertSame(900, $guard->secondsRemaining(1_000_000));
        $this->assertSame(300, $guard->secondsRemaining(1_000_600));
    }

    /**
     * Old failures must not accumulate into a block, or a quiet site would
     * eventually refuse logins on the strength of last month's typos.
     */
    public function testFailuresOlderThanTheWindowDoNotCount(): void
    {
        $guard = $this->guard(3, 900);

        $guard->recordFailure(1_000_000);
        $guard->recordFailure(1_000_100);
        // A day later, two more. The first pair is long out of the window.
        $guard->recordFailure(1_086_400);
        $guard->recordFailure(1_086_401);

        $this->assertFalse($guard->isTripped(1_086_401));
    }

    /**
     * A spray is not a burst that stops — the ceiling has to keep applying to a
     * slow, sustained attack that keeps topping the window up.
     */
    public function testASustainedAttackStaysBlocked(): void
    {
        $guard = $this->guard(3, 900);
        $now = 1_000_000;

        for ($i = 0; $i < 3; $i++) {
            $guard->recordFailure($now + ($i * 60));
        }

        // Attempts keep coming every minute; the window keeps being refilled.
        for ($i = 3; $i < 30; $i++) {
            $at = $now + ($i * 60);
            $this->assertTrue($guard->isTripped($at), "still blocked at minute {$i}");
            $guard->recordFailure($at);
        }
    }

    /* --------------------------------------------------------- containment -- */

    /**
     * An attack is thousands of attempts. The state it leaves behind must be
     * bounded by the threshold, not by the attacker's patience.
     */
    public function testTheStateFileDoesNotGrowWithTheAttack(): void
    {
        $guard = $this->guard(5, 900);

        for ($i = 0; $i < 2_000; $i++) {
            $guard->recordFailure(1_000_000 + $i);
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        $this->assertCount(5, $decoded['failures']);
        $this->assertTrue($guard->isTripped(1_002_000), 'and it is still counting correctly');
    }

    /**
     * Losing the count costs one window of protection; throwing would cost
     * every login on the site, which is the worse of the two failures.
     */
    public function testACorruptStateFileReadsAsNoFailures(): void
    {
        file_put_contents($this->path, 'not json at all');

        $this->assertFalse($this->guard()->isTripped(1_000_000));
    }

    public function testAThresholdOfZeroTurnsTheCeilingOff(): void
    {
        // For an operator who bounds login rate in front of the application and
        // wants no second opinion from it.
        $guard = $this->guard(0);

        for ($i = 0; $i < 100; $i++) {
            $guard->recordFailure(1_000_000 + $i);
        }

        $this->assertFalse($guard->isTripped(1_000_100));
    }
}
