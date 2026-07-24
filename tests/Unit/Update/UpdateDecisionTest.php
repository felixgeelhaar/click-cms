<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Domain\Update\Release;
use Click\Cms\Domain\Update\SemanticVersion;
use Click\Cms\Domain\Update\UpdateDecision;
use Click\Cms\Domain\Update\UpdatePolicy;
use Click\Cms\Domain\Update\UpdateStep;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide when this software installs new code without a human
 * present. Every branch is pinned, because the failure modes are "silently ran
 * a breaking release" and "silently skipped a security fix".
 */
final class UpdateDecisionTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function release(string $version, bool $security = false, string $requiresPhp = ''): Release
    {
        $r = Release::fromArray([
            'version' => $version,
            'packageUrl' => 'https://example.com/click-cms-' . $version . '.zip',
            'sha256' => self::SHA,
            'security' => $security,
            'requiresPhp' => $requiresPhp,
        ]);
        $this->assertNotNull($r, "fixture $version should parse");

        return $r;
    }

    private function decide(string $current, array $releases, UpdatePolicy $policy, string $php = '8.3.0', bool $pre = false): UpdateDecision
    {
        return UpdateDecision::decide(SemanticVersion::fromString($current), $releases, $policy, $php, $pre);
    }

    /* ------------------------------------------------------- what is offered -- */

    public function testUpToDateWhenNothingIsNewer(): void
    {
        $d = $this->decide('1.4.2', [$this->release('1.4.2'), $this->release('1.3.0')], UpdatePolicy::Security);

        $this->assertFalse($d->hasUpdate());
        $this->assertStringContainsString('up to date', $d->reason);
    }

    public function testOffersTheNewestApplicableRelease(): void
    {
        $d = $this->decide('1.4.2', [$this->release('1.5.0'), $this->release('1.10.0'), $this->release('1.6.0')], UpdatePolicy::Notify);

        $this->assertTrue($d->hasUpdate());
        // 1.10.0, not 1.6.0 — the comparison is numeric.
        $this->assertSame('1.10.0', $d->release->version->toString());
    }

    public function testManualNeverEvenLooks(): void
    {
        $d = $this->decide('1.0.0', [$this->release('9.9.9')], UpdatePolicy::Manual);

        $this->assertFalse($d->hasUpdate());
        $this->assertStringContainsString('manual', $d->reason);
    }

    public function testAReleaseNeedingNewerPhpIsNotOffered(): void
    {
        $d = $this->decide('1.4.2', [$this->release('2.0.0', requiresPhp: '8.4')], UpdatePolicy::Notify, php: '8.3.0');

        $this->assertFalse($d->hasUpdate(), 'must not offer what the site cannot run');
    }

    public function testPreReleasesAreHiddenUnlessAskedFor(): void
    {
        $releases = [$this->release('2.0.0-beta.1')];

        $this->assertFalse($this->decide('1.0.0', $releases, UpdatePolicy::Notify)->hasUpdate());
        $this->assertTrue($this->decide('1.0.0', $releases, UpdatePolicy::Notify, pre: true)->hasUpdate());
    }

    /* --------------------------------------------------- what may auto-install -- */

    public function testNotifyNeverInstallsByItself(): void
    {
        $d = $this->decide('1.4.2', [$this->release('1.4.3', security: true)], UpdatePolicy::Notify);

        $this->assertTrue($d->hasUpdate());
        $this->assertFalse($d->automatic, 'notify means tell me, not do it');
    }

    public function testSecurityPolicyInstallsASecurityPatchButNotAnOrdinaryOne(): void
    {
        $secure = $this->decide('1.4.2', [$this->release('1.4.3', security: true)], UpdatePolicy::Security);
        $this->assertTrue($secure->automatic);

        $ordinary = $this->decide('1.4.2', [$this->release('1.4.3')], UpdatePolicy::Security);
        $this->assertTrue($ordinary->hasUpdate());
        $this->assertFalse($ordinary->automatic, 'a non-security patch still waits for a human');
    }

    public function testSecurityPolicyStillRefusesAMajorEvenWhenItIsASecurityFix(): void
    {
        $d = $this->decide('1.4.2', [$this->release('2.0.0', security: true)], UpdatePolicy::Security);

        $this->assertTrue($d->hasUpdate());
        $this->assertSame(UpdateStep::Major, $d->step);
        $this->assertFalse($d->automatic, 'a major may break the site; it needs a human');
    }

    public function testMinorPolicyTakesPatchAndMinorButNotMajor(): void
    {
        $this->assertTrue($this->decide('1.4.2', [$this->release('1.4.3')], UpdatePolicy::Minor)->automatic);
        $this->assertTrue($this->decide('1.4.2', [$this->release('1.5.0')], UpdatePolicy::Minor)->automatic);
        $this->assertFalse($this->decide('1.4.2', [$this->release('2.0.0')], UpdatePolicy::Minor)->automatic);
    }

    public function testAllPolicyTakesEverythingIncludingAMajor(): void
    {
        $this->assertTrue($this->decide('1.4.2', [$this->release('2.0.0')], UpdatePolicy::All)->automatic);
    }

    public function testAPreReleaseIsNeverInstalledUnattended(): void
    {
        // Even on the most permissive policy, and even when explicitly opted in.
        $d = $this->decide('1.0.0', [$this->release('1.0.1-rc.1')], UpdatePolicy::All, pre: true);

        $this->assertTrue($d->hasUpdate());
        $this->assertFalse($d->automatic);
    }

    /**
     * Skipping straight to the newest release still counts as taking the security
     * fix that sits in the middle of the range — otherwise a site on the security
     * policy would decline precisely the update it exists to take.
     */
    public function testASecurityFixAnywhereInTheSkippedRangeMakesTheUpdateAutomatic(): void
    {
        $d = $this->decide(
            '1.4.0',
            [$this->release('1.4.1', security: true), $this->release('1.4.2')],
            UpdatePolicy::Security
        );

        $this->assertSame('1.4.2', $d->release->version->toString());
        $this->assertTrue($d->automatic);
    }

    /* ------------------------------------------------------------- feed hygiene -- */

    public function testAMalformedReleaseIsIgnoredRatherThanOffered(): void
    {
        $this->assertNull(Release::fromArray(['version' => 'latest', 'packageUrl' => 'https://x/y.zip', 'sha256' => self::SHA]));
        $this->assertNull(Release::fromArray(['version' => '1.0.0', 'packageUrl' => '', 'sha256' => self::SHA]));
        $this->assertNull(Release::fromArray(['version' => '1.0.0', 'packageUrl' => 'https://x/y.zip', 'sha256' => 'short']));
    }

    public function testAPackageUrlThatIsNotHttpIsRefused(): void
    {
        $this->assertNull(Release::fromArray([
            'version' => '1.0.0',
            'packageUrl' => 'file:///etc/passwd',
            'sha256' => self::SHA,
        ]));
    }
}
