<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Site;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * Two sites, two configurations, at the kernel.
 *
 * `LayeredCoreConfigTest` pins the merge rules. This pins that the kernel
 * actually applies them — that a request arriving on one hostname is served with
 * that site's languages and storage rather than its neighbour's.
 *
 * It is the assertion the feature exists for: until this held, an agency running
 * eight client sites had one set of languages and one storage backend between
 * them.
 */
final class PerSiteConfigTest extends TestCase
{
    private string $root;
    private ?string $previousHost = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-persite-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o775, true);
        mkdir($this->root . '/sites/acme/config', 0o775, true);

        $this->previousHost = $_SERVER['HTTP_HOST'] ?? null;

        file_put_contents($this->root . '/config/sites.json', (string) json_encode(['sites' => [
            ['id' => 'primary', 'hosts' => ['main.example.com']],
            ['id' => 'acme', 'hosts' => ['acme.example.com']],
        ]]));

        file_put_contents($this->root . '/config/core.json', (string) json_encode(['core' => [
            'storage' => ['backend' => 'json'],
            'languages' => ['default' => 'en', 'available' => ['en']],
            'cache' => ['enabled' => false],
            'updates' => ['policy' => 'security'],
        ]]));
    }

    protected function tearDown(): void
    {
        if ($this->previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->previousHost;
        }

        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /** @param array<string, mixed> $config */
    private function giveAcme(array $config): void
    {
        file_put_contents($this->root . '/sites/acme/config/core.json', (string) json_encode($config));
    }

    private function configFor(string $host): \Click\Cms\Application\Config\CoreConfig
    {
        $_SERVER['HTTP_HOST'] = $host;
        $app = new Application($this->root);

        // `loadCoreConfig` is private and runs during boot; the parsed result is
        // reachable through the public accessor the rest of the app uses.
        $reflection = new \ReflectionMethod($app, 'loadCoreConfig');
        $reflection->invoke($app);

        $property = new \ReflectionProperty($app, 'coreConfig');

        return \Click\Cms\Application\Config\CoreConfig::fromArray($property->getValue($app));
    }

    /* ------------------------------------------------------------------- */

    public function testASiteWithNoConfigOfItsOwnUsesTheInstallations(): void
    {
        $config = $this->configFor('acme.example.com');

        $this->assertSame('en', $config->defaultLocale()->code);
    }

    /**
     * The assertion the whole feature exists for.
     */
    public function testTwoSitesGetTheirOwnLanguages(): void
    {
        $this->giveAcme(['core' => ['languages' => ['default' => 'de', 'available' => ['de', 'fr']]]]);

        $this->assertSame('en', $this->configFor('main.example.com')->defaultLocale()->code);
        $this->assertSame('de', $this->configFor('acme.example.com')->defaultLocale()->code);
    }

    public function testASiteGetsItsOwnStorageBackend(): void
    {
        $this->giveAcme(['core' => ['storage' => ['backend' => 'sqlite']]]);

        $this->assertSame('json', $this->configFor('main.example.com')->storageBackend());
        $this->assertSame('sqlite', $this->configFor('acme.example.com')->storageBackend());
    }

    public function testASiteGetsItsOwnSingleSignOn(): void
    {
        $this->giveAcme(['core' => ['sso' => [
            'enabled' => true,
            'issuer' => 'https://id.acme.test',
            'clientId' => 'acme',
            'clientSecret' => 'shh',
            'redirectUri' => 'https://acme.example.com/api/auth/sso/callback',
        ]]]);

        $this->assertSame([], $this->configFor('main.example.com')->sso());
        $this->assertSame('https://id.acme.test', $this->configFor('acme.example.com')->sso()['issuer']);
    }

    /**
     * A site setting one key keeps the installation's answer for the rest,
     * rather than falling back to a built-in default nobody chose.
     */
    public function testASiteKeepsTheInstallationsValuesItDidNotOverride(): void
    {
        $this->giveAcme(['core' => ['languages' => ['default' => 'de']]]);

        $config = $this->configFor('acme.example.com');

        $this->assertSame('de', $config->defaultLocale()->code);
        $this->assertFalse($config->cacheEnabled());
    }

    /**
     * Self-update installs code into one tree every site runs, so a per-site
     * policy would be two answers to a question with one outcome.
     */
    public function testASiteCannotGiveItselfADifferentUpdatePolicy(): void
    {
        $this->giveAcme(['core' => ['updates' => ['policy' => 'all']]]);

        $this->assertSame(
            $this->configFor('main.example.com')->updatePolicy(),
            $this->configFor('acme.example.com')->updatePolicy(),
        );
    }

    /**
     * A malformed site config must not take that client's site down when the
     * installation's configuration is perfectly usable.
     */
    public function testAMalformedSiteConfigFallsBackRatherThanFailing(): void
    {
        file_put_contents($this->root . '/sites/acme/config/core.json', '{ not json');

        $this->assertSame('en', $this->configFor('acme.example.com')->defaultLocale()->code);
    }
}
