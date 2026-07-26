<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Preflight;

use Click\Cms\Application\Preflight\CheckStatus;
use Click\Cms\Application\Preflight\HostReport;
use PHPUnit\Framework\TestCase;

/**
 * What a host has to provide, decided from facts rather than by asking the host
 * itself — so every verdict can be pinned without a server that has the fault.
 *
 * The distinction the whole report turns on is failure versus warning. A failure
 * means the CMS will not run: installing anyway wastes an afternoon. A warning
 * means it will run with something missing — smaller images, no unattended
 * updates — which is a decision, not a blocker. Anything that is merely useful
 * to know is neither.
 */
final class HostReportTest extends TestCase
{
    /** A host with nothing wrong with it. */
    private function healthy(): array
    {
        return [
            'phpVersionId' => 80_300,
            'phpVersion' => '8.3.0',
            'sapi' => 'apache2handler',
            'extensions' => ['mbstring', 'gd', 'fileinfo', 'curl', 'openssl'],
            'uploadMaxBytes' => 64 * 1024 * 1024,
            'postMaxBytes' => 64 * 1024 * 1024,
            'memoryLimit' => '256M',
            'maxExecutionTime' => '60',
            'allowUrlFopen' => true,
            'documentRoot' => '/web',
            'publicDir' => '/web/cms',
            'publicDirWritable' => true,
            'outsideRoot' => '/home/example',
            'outsideRootWritable' => true,
        ];
    }

    /** @return array<string, \Click\Cms\Application\Preflight\HostCheck> */
    private function byName(array $facts): array
    {
        $out = [];
        foreach (HostReport::for($facts) as $check) {
            $out[$check->name] = $check;
        }

        return $out;
    }

    public function testAHealthyHostBlocksNothing(): void
    {
        $checks = HostReport::for($this->healthy());

        $this->assertSame(0, HostReport::failures($checks));
        $this->assertSame(0, HostReport::warnings($checks));
    }

    /* ------------------------------------------------------------- fatal -- */

    public function testAPhpOlderThanTheFloorFails(): void
    {
        $checks = $this->byName(['phpVersionId' => 80_009, 'phpVersion' => '8.0.9'] + $this->healthy());

        $this->assertSame(CheckStatus::Failed, $checks['PHP version']->status);
        $this->assertStringContainsString('8.1', $checks['PHP version']->detail);
    }

    public function testTheFloorItselfPasses(): void
    {
        $checks = $this->byName(['phpVersionId' => 80_100, 'phpVersion' => '8.1.0'] + $this->healthy());

        $this->assertSame(CheckStatus::Ok, $checks['PHP version']->status);
    }

    /** Used unconditionally for text handling; without it the CMS breaks. */
    public function testMissingMbstringFails(): void
    {
        $facts = $this->healthy();
        $facts['extensions'] = ['gd', 'fileinfo', 'curl', 'openssl'];

        $this->assertSame(CheckStatus::Failed, $this->byName($facts)['mbstring']->status);
    }

    /**
     * An upload ceiling below what the CMS itself accepts is a failure and not a
     * warning: it is discovered halfway through moving a site's media, which is
     * the worst moment to discover it.
     */
    public function testAnUploadCeilingBelowTheCmsLimitFails(): void
    {
        $facts = $this->healthy();
        $facts['uploadMaxBytes'] = 2 * 1024 * 1024;

        $check = $this->byName($facts)['upload size'];
        $this->assertSame(CheckStatus::Failed, $check->status);
        $this->assertStringContainsString('2 MB', $check->detail);
    }

    /** post_max_size caps an upload just as effectively, and is easy to miss. */
    public function testAPostCeilingBelowTheUploadCeilingFails(): void
    {
        $facts = $this->healthy();
        $facts['postMaxBytes'] = 8 * 1024 * 1024;

        $this->assertSame(CheckStatus::Failed, $this->byName($facts)['upload size']->status);
    }

    /* ----------------------------------------------------------- warning -- */

    /** Documented as optional: uploads still work, at the size they arrived. */
    public function testMissingGdWarnsRatherThanFails(): void
    {
        $facts = $this->healthy();
        $facts['extensions'] = ['mbstring', 'fileinfo', 'curl', 'openssl'];

        $check = $this->byName($facts)['gd'];
        $this->assertSame(CheckStatus::Warning, $check->status);
        $this->assertStringContainsString('variant', $check->detail);
    }

    public function testNoWayToReachTheUpdateFeedWarns(): void
    {
        $facts = $this->healthy();
        $facts['extensions'] = ['mbstring', 'gd', 'fileinfo', 'openssl'];
        $facts['allowUrlFopen'] = false;

        $this->assertSame(CheckStatus::Warning, $this->byName($facts)['outbound HTTPS']->status);
    }

    /** Either route out is enough; curl is not required specifically. */
    public function testAllowUrlFopenAloneIsEnough(): void
    {
        $facts = $this->healthy();
        $facts['extensions'] = ['mbstring', 'gd', 'fileinfo', 'openssl'];

        $this->assertSame(CheckStatus::Ok, $this->byName($facts)['outbound HTTPS']->status);
    }

    public function testMissingOpensslWarnsBecauseUpdatesCannotBeVerified(): void
    {
        $facts = $this->healthy();
        $facts['extensions'] = ['mbstring', 'gd', 'fileinfo', 'curl'];

        $check = $this->byName($facts)['openssl'];
        $this->assertSame(CheckStatus::Warning, $check->status);
        $this->assertStringContainsString('signature', $check->detail);
    }

    /**
     * The most consequential thing the report says, because it decides the
     * install layout: with nowhere writable outside the served tree, content/,
     * data/ and config/ have to sit inside it and be denied by rewrite rules
     * instead. That works — so it is a warning — but the operator has to know
     * they are relying on those rules staying in place.
     */
    public function testNoSpaceOutsideTheDocumentRootWarnsAndSaysWhatItMeans(): void
    {
        $facts = $this->healthy();
        $facts['outsideRootWritable'] = false;

        $check = $this->byName($facts)['space outside the document root'];
        $this->assertSame(CheckStatus::Warning, $check->status);
        $this->assertStringContainsString('.htaccess', $check->detail);
    }

    public function testAnUnwritablePublicDirectoryFails(): void
    {
        $facts = $this->healthy();
        $facts['publicDirWritable'] = false;

        $this->assertSame(CheckStatus::Failed, $this->byName($facts)['public directory writable']->status);
    }

    /* -------------------------------------------------------------- info -- */

    public function testFactsWithNoVerdictAreReportedAnyway(): void
    {
        $checks = $this->byName($this->healthy());

        $this->assertSame(CheckStatus::Info, $checks['SAPI']->status);
        $this->assertSame('apache2handler', $checks['SAPI']->detail);
        $this->assertSame(CheckStatus::Info, $checks['document root']->status);
    }

    /**
     * A missing fact must not be read as a fault. The report is also run from
     * places that cannot know everything — an installation that does not exist
     * yet has no content directory to test.
     */
    public function testAnAbsentFactIsNotAFailure(): void
    {
        $checks = HostReport::for([]);

        $this->assertNotSame([], $checks);
        // Nothing is claimed to be fine either: an empty environment fails the
        // version check because "no PHP version" is not a version we support.
        $this->assertGreaterThan(0, HostReport::failures($checks));
    }
}
