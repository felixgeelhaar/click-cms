<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * The version the code reports is the version the changelog says was released.
 *
 * `Application::VERSION` is what the updater compares against a signed feed to
 * answer "is there anything newer than what I am running?". Its own docblock
 * says bumping it is part of cutting a release — and it was missed for four
 * consecutive releases, so an installation running 1.4.4 reported 1.3.0, was
 * offered 1.4.4 forever, and would have re-installed it on every unattended run.
 *
 * A process note did not hold, so this holds it instead: the check runs on every
 * push, not only at release time, which is when the mistake is cheap.
 */
final class VersionMatchesChangelogTest extends TestCase
{
    public function testTheCodeReportsTheVersionTheChangelogReleased(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 3) . '/CHANGELOG.md');

        // The first `## [x.y.z]` heading is the most recent release. An
        // "Unreleased" heading is skipped: work in progress is not a version.
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $matches);

        $this->assertNotEmpty($matches, 'the changelog should name a released version');
        $this->assertSame(
            $matches[1],
            Application::VERSION,
            'Application::VERSION and the newest CHANGELOG entry disagree — one of them was not updated when the release was cut'
        );
    }

    /** The shape the feed and the packer both expect. */
    public function testTheVersionIsPlainSemver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Application::VERSION);
    }
}
