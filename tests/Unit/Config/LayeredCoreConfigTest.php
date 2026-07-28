<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Config;

use Click\Cms\Application\Config\LayeredCoreConfig;
use PHPUnit\Framework\TestCase;

/**
 * A site's `config/core.json` laid over the installation's.
 *
 * Multi-site gave each site its own content, media, accounts and settings, and
 * left `config/core.json` shared — so eight client sites had one storage
 * backend, one set of languages and one identity provider between them. This is
 * what un-shares it.
 *
 * The interesting decisions are all about what a site is *not* allowed to
 * override. Self-update installs code into one directory tree that every site
 * runs; a per-site update policy would be two answers to a question with one
 * outcome, and the last site to be asked would win by accident.
 */
final class LayeredCoreConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $installation
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function merge(array $installation, array $site): array
    {
        return (new LayeredCoreConfig())->effective($installation, $site);
    }

    /* ----------------------------------------------------------- basics -- */

    public function testASiteWithNoConfigGetsTheInstallationsUnchanged(): void
    {
        $installation = ['core' => ['languages' => ['default' => 'en']]];

        $this->assertSame($installation, $this->merge($installation, []));
    }

    public function testASiteOverridesOnlyWhatItDeclares(): void
    {
        $effective = $this->merge(
            ['core' => ['languages' => ['default' => 'en', 'available' => ['en']], 'cache' => ['enabled' => false]]],
            ['core' => ['languages' => ['default' => 'de']]],
        );

        $this->assertSame('de', $effective['core']['languages']['default']);
        // Untouched siblings survive: a site setting one language key does not
        // erase the list beside it.
        $this->assertSame(['en'], $effective['core']['languages']['available']);
        $this->assertFalse($effective['core']['cache']['enabled']);
    }

    public function testASiteCanIntroduceAKeyTheInstallationNeverSet(): void
    {
        $effective = $this->merge(
            ['core' => ['languages' => ['default' => 'en']]],
            ['core' => ['sso' => ['enabled' => true, 'issuer' => 'https://id.acme.test']]],
        );

        $this->assertTrue($effective['core']['sso']['enabled']);
        $this->assertSame('en', $effective['core']['languages']['default']);
    }

    /**
     * A list is replaced, not concatenated. `available: ["de"]` means this site
     * publishes German — not German *as well as* whatever the installation
     * listed, which would make it impossible to narrow a set.
     */
    public function testAListIsReplacedRatherThanAppended(): void
    {
        $effective = $this->merge(
            ['core' => ['languages' => ['available' => ['en', 'fr', 'es']]]],
            ['core' => ['languages' => ['available' => ['de']]]],
        );

        $this->assertSame(['de'], $effective['core']['languages']['available']);
    }

    /* --------------------------------------------- installation-only keys -- */

    /**
     * The rule the whole class turns on. Self-update replaces `src/` in one
     * directory tree that every site runs, so "which releases install
     * themselves" has exactly one answer per installation. A site declaring its
     * own would be asking a question that cannot have two answers, and whichever
     * site the updater happened to run as would decide for all of them.
     */
    public function testASiteCannotOverrideTheUpdatePolicy(): void
    {
        $effective = $this->merge(
            ['core' => ['updates' => ['policy' => 'security', 'feedUrl' => 'https://example.test/feed.json']]],
            ['core' => ['updates' => ['policy' => 'all']]],
        );

        $this->assertSame('security', $effective['core']['updates']['policy']);
        $this->assertSame('https://example.test/feed.json', $effective['core']['updates']['feedUrl']);
    }

    /**
     * The marketplace installs plugin code into the shared `plugins/`
     * directory, so where it installs from is likewise one answer.
     */
    public function testASiteCannotOverrideTheMarketplace(): void
    {
        $effective = $this->merge(
            ['core' => ['marketplace' => ['registryUrl' => 'https://trusted.test/registry.json']]],
            ['core' => ['marketplace' => ['registryUrl' => 'https://attacker.test/registry.json']]],
        );

        $this->assertSame('https://trusted.test/registry.json', $effective['core']['marketplace']['registryUrl']);
    }

    /**
     * Ignoring an override silently is the failure this codebase keeps
     * removing, so the refusals are reported to the caller to log.
     */
    public function testRefusedOverridesAreReported(): void
    {
        $config = new LayeredCoreConfig();
        $config->effective(
            ['core' => ['updates' => ['policy' => 'security']]],
            ['core' => ['updates' => ['policy' => 'all'], 'marketplace' => ['enabled' => true]]],
        );

        $refused = $config->refused();

        $this->assertContains('core.updates', $refused);
        $this->assertContains('core.marketplace', $refused);
    }

    public function testNothingIsReportedWhenASiteOverstepsNothing(): void
    {
        $config = new LayeredCoreConfig();
        $config->effective(
            ['core' => ['updates' => ['policy' => 'security']]],
            ['core' => ['languages' => ['default' => 'de']]],
        );

        $this->assertSame([], $config->refused());
    }

    /* ------------------------------------------------------ what is allowed -- */

    /**
     * The point of the exercise: the things an agency actually needs to differ
     * between client sites.
     */
    public function testTheThingsASiteMostNeedsAreOverridable(): void
    {
        $effective = $this->merge(
            ['core' => [
                'storage' => ['backend' => 'json'],
                'languages' => ['default' => 'en', 'available' => ['en']],
                'sso' => ['enabled' => false],
                'cache' => ['enabled' => false],
            ]],
            ['core' => [
                'storage' => ['backend' => 'sqlite', 'sqlite' => ['path' => 'data/content.sqlite']],
                'languages' => ['default' => 'de', 'available' => ['de', 'fr']],
                'sso' => ['enabled' => true, 'issuer' => 'https://id.acme.test'],
                'cache' => ['enabled' => true],
            ]],
        );

        $this->assertSame('sqlite', $effective['core']['storage']['backend']);
        $this->assertSame('de', $effective['core']['languages']['default']);
        $this->assertSame(['de', 'fr'], $effective['core']['languages']['available']);
        $this->assertTrue($effective['core']['sso']['enabled']);
        $this->assertTrue($effective['core']['cache']['enabled']);
    }

    /**
     * Which plugins load is per site on purpose: one client gets the visual
     * builder and another does not, without two installations. The plugin
     * *code* is still shared — this only decides what boots.
     */
    public function testASiteCanExcludePluginsTheInstallationLoads(): void
    {
        $effective = $this->merge(
            ['core' => ['plugins' => ['exclude' => ['ids' => []]]]],
            ['core' => ['plugins' => ['exclude' => ['ids' => ['visual-builder']]]]],
        );

        $this->assertSame(['visual-builder'], $effective['core']['plugins']['exclude']['ids']);
    }

    /* ---------------------------------------------------------- oddities -- */

    public function testAnEmptyInstallationConfigIsNotAnError(): void
    {
        $effective = $this->merge([], ['core' => ['cache' => ['enabled' => true]]]);

        $this->assertTrue($effective['core']['cache']['enabled']);
    }

    /**
     * A site replacing an object with a scalar, or the reverse, takes the
     * site's value rather than half-merging into something neither side meant.
     */
    public function testAShapeChangeTakesTheSitesValueWhole(): void
    {
        $effective = $this->merge(
            ['core' => ['media' => ['crops' => ['a' => 1]]]],
            ['core' => ['media' => ['crops' => []]]],
        );

        $this->assertSame([], $effective['core']['media']['crops']);
    }
}
