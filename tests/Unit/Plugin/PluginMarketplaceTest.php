<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Application\Plugin\PluginMarketplace;
use PHPUnit\Framework\TestCase;

/**
 * Installing a plugin is running its code, so the archive is treated as hostile.
 * These pin down the two things that matter: the extraction cannot be tricked
 * into writing outside the plugins directory (Zip Slip and friends), and the
 * registry path only installs what a signed manifest and a matching checksum
 * vouch for.
 */
final class PluginMarketplaceTest extends TestCase
{
    private string $base;
    private PluginMarketplace $marketplace;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-mp-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/plugins', 0o755, true);
        mkdir($this->base . '/data', 0o755, true);

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $this->marketplace = new PluginMarketplace($manager, $this->base);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
        // The Zip Slip test aims at this sentinel outside the base; make sure a
        // stray one never survives a run.
        @unlink(sys_get_temp_dir() . '/click-cms-ZIPSLIP-SENTINEL.php');
    }

    /* ----------------------------------------------------------- helpers -- */

    /**
     * @param array<string, string> $entries entry name => contents
     */
    private function makeZip(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    private function validPluginZip(string $path, string $name = 'Sample Plugin'): void
    {
        $this->makeZip($path, [
            'sample/plugin.json' => json_encode(['name' => $name, 'version' => '1.0.0']),
            'sample/bootstrap.php' => "<?php\n// harmless\n",
        ]);
    }

    /* ------------------------------------------------------- local install -- */

    public function testInstallsAValidPluginFromAZip(): void
    {
        $zip = $this->base . '/data/pkg.zip';
        $this->validPluginZip($zip);

        $result = $this->marketplace->installFromZip($zip);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('sample-plugin', $result['plugin']['id']);
        $this->assertFileExists($this->base . '/plugins/sample-plugin/plugin.json');
        $this->assertFileExists($this->base . '/plugins/sample-plugin/bootstrap.php');
    }

    public function testRefusesToOverwriteAnExistingPlugin(): void
    {
        $zip = $this->base . '/data/pkg.zip';
        $this->validPluginZip($zip);
        $this->marketplace->installFromZip($zip);

        // A second identical install must not clobber the first.
        $again = $this->marketplace->installFromZip($zip);
        $this->assertFalse($again['success']);
        $this->assertStringContainsString('already exists', $again['error']);
    }

    /* -------------------------------------------------------- extraction -- */

    public function testRefusesAnArchiveThatEscapesWithDotDot(): void
    {
        $sentinel = sys_get_temp_dir() . '/click-cms-ZIPSLIP-SENTINEL.php';
        @unlink($sentinel);

        $zip = $this->base . '/data/evil.zip';
        // Enough `..` to climb out of data/.extract-* into the system temp dir.
        $this->makeZip($zip, [
            'sample/plugin.json' => json_encode(['name' => 'Evil', 'version' => '1.0.0']),
            '../../../../../../../../../../../../tmp/click-cms-ZIPSLIP-SENTINEL.php' => '<?php echo "pwned";',
        ]);

        $result = $this->marketplace->installFromZip($zip);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', $result['error']);
        // The whole point: nothing was written outside the tree.
        $this->assertFileDoesNotExist($sentinel);
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/evil');
    }

    public function testRefusesAnArchiveWithAnAbsolutePathEntry(): void
    {
        $zip = $this->base . '/data/abs.zip';
        $this->makeZip($zip, [
            'sample/plugin.json' => json_encode(['name' => 'Abs', 'version' => '1.0.0']),
            '/etc/click-cms-should-never-write' => 'x',
        ]);

        $result = $this->marketplace->installFromZip($zip);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', $result['error']);
    }

    public function testRefusesAnArchiveWithTooManyEntries(): void
    {
        $zip = $this->base . '/data/many.zip';
        $entries = ['sample/plugin.json' => json_encode(['name' => 'Many', 'version' => '1.0.0'])];
        for ($i = 0; $i < 2001; $i++) {
            $entries["sample/f{$i}.txt"] = 'x';
        }
        $this->makeZip($zip, $entries);

        $result = $this->marketplace->installFromZip($zip);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('too many entries', $result['error']);
    }

    public function testRefusesAZipWithoutAPluginManifest(): void
    {
        $zip = $this->base . '/data/nomanifest.zip';
        $this->makeZip($zip, ['sample/readme.txt' => 'hello']);

        $result = $this->marketplace->installFromZip($zip);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('plugin.json', $result['error']);
    }

    public function testRefusesAPackageWhoseIdDoesNotMatchTheSignedId(): void
    {
        $zip = $this->base . '/data/pkg.zip';
        // The package installs as "sample-plugin", but the registry vouched for
        // "trusted-plugin" — the signature guaranteed nothing about this code.
        $this->validPluginZip($zip);

        $result = $this->marketplace->installFromZip($zip, 'trusted-plugin');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not match the signed', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/sample-plugin');
    }

    /* ---------------------------------------------------- registry install -- */

    /**
     * The whole signed chain end to end, over file:// URLs: registry → signed
     * manifest → checksummed package → safe extraction → install.
     *
     * @return array{registryUrl: string, publicKey: string, privateKey: \OpenSSLAsymmetricKey}
     */
    private function publishRegistry(string $pluginId, string $packageName, ?callable $tamper = null): array
    {
        $keypair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keypair === false) {
            $this->markTestSkipped('OpenSSL key generation is unavailable.');
        }
        $details = openssl_pkey_get_details($keypair);
        $publicKey = $details['key'];

        $registryDir = $this->base . '/registry';
        mkdir($registryDir, 0o755, true);

        // The package the manifest points at, and its real hash.
        $packagePath = $registryDir . '/package.zip';
        $this->makeZip($packagePath, [
            'p/plugin.json' => json_encode(['name' => $packageName, 'version' => '2.1.0']),
            'p/bootstrap.php' => "<?php\n",
        ]);
        $sha256 = hash_file('sha256', $packagePath);

        $manifest = [
            'id' => $pluginId,
            'version' => '2.1.0',
            'packageUrl' => 'file://' . $packagePath,
            'sha256' => $sha256,
        ];
        if ($tamper !== null) {
            $manifest = $tamper($manifest);
        }

        $manifestRaw = json_encode($manifest);
        $manifestPath = $registryDir . '/manifest.json';
        file_put_contents($manifestPath, $manifestRaw);

        // Sign the exact manifest bytes as they will be fetched.
        openssl_sign((string) file_get_contents($manifestPath), $sig, $keypair, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($sig);

        $registryPath = $registryDir . '/registry.json';
        file_put_contents($registryPath, json_encode([
            'plugins' => [
                ['manifestUrl' => 'file://' . $manifestPath, 'signature' => $signature],
            ],
        ]));

        return [
            'registryUrl' => 'file://' . $registryPath,
            'publicKey' => $publicKey,
            'privateKey' => $keypair,
        ];
    }

    public function testInstallsFromASignedRegistryEndToEnd(): void
    {
        $reg = $this->publishRegistry('sample-plugin', 'Sample Plugin');

        $result = $this->marketplace->installFromRegistry(
            $reg['registryUrl'],
            $reg['publicKey'],
            'sample-plugin',
            '2.1.0'
        );

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertFileExists($this->base . '/plugins/sample-plugin/plugin.json');
    }

    public function testRejectsATamperedManifest(): void
    {
        // Sign a manifest, then flip a byte of what the catalog verifies by
        // pointing at a public key from a *different* keypair.
        $reg = $this->publishRegistry('sample-plugin', 'Sample Plugin');
        $otherKey = openssl_pkey_get_details(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]))['key'];

        $result = $this->marketplace->installFromRegistry($reg['registryUrl'], $otherKey, 'sample-plugin');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signature', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/sample-plugin');
    }

    public function testRejectsAChecksumMismatch(): void
    {
        // The manifest is validly signed but claims a hash the package does not
        // have, so the download is refused after verification.
        $reg = $this->publishRegistry(
            'sample-plugin',
            'Sample Plugin',
            static function (array $manifest): array {
                $manifest['sha256'] = str_repeat('0', 64);
                return $manifest;
            }
        );

        $result = $this->marketplace->installFromRegistry($reg['registryUrl'], $reg['publicKey'], 'sample-plugin');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('checksum', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/sample-plugin');
    }

    public function testRejectsAPackageThatInstallsUnderADifferentId(): void
    {
        // The registry vouches for "trusted-plugin" but the package's own
        // plugin.json names something else, so it would install under a different
        // id than the one signed.
        $reg = $this->publishRegistry('trusted-plugin', 'Totally Different');

        $result = $this->marketplace->installFromRegistry($reg['registryUrl'], $reg['publicKey'], 'trusted-plugin');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not match the signed', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/trusted-plugin');
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/totally-different');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
