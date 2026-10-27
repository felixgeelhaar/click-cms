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

    public function testUploadedCodeStaysInactiveAcrossBootsUntilExplicitActivation(): void
    {
        $zip = $this->base . '/data/pkg.zip';
        $marker = $this->base . '/bootstrap-executed';
        $this->makeZip($zip, [
            'sample/plugin.json' => json_encode(['name' => 'Sample Plugin', 'version' => '1.0.0']),
            'sample/bootstrap.php' => '<?php file_put_contents(' . var_export($marker, true) . ', "ran");',
        ]);
        self::assertTrue($this->marketplace->installFromZip($zip)['success']);
        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        foreach ($manager->discover() as $plugin) {
            self::assertTrue($manager->isDeactivated($plugin->id));
            if (!$manager->isDeactivated($plugin->id)) {
                $manager->activate($plugin->id);
            }
        }
        self::assertFileDoesNotExist($marker);
        self::assertTrue($manager->activate(\Click\Cms\Domain\ValueObjects\PluginId::fromString('sample-plugin'))['success']);
        self::assertFileExists($marker);
    }

    public function testFailedActivationGateDoesNotPublishUploadedCode(): void
    {
        mkdir($this->base . '/data/plugin-state.json');
        $zip = $this->base . '/data/pkg.zip';
        $this->validPluginZip($zip);
        $result = $this->marketplace->installFromZip($zip);
        self::assertFalse($result['success']);
        self::assertSame('Could not persist plugin activation state.', $result['error']);
        self::assertDirectoryDoesNotExist($this->base . '/plugins/sample-plugin');
    }

    public function testRefusesAnExistingIdEvenWhenItsFolderHasADifferentName(): void
    {
        mkdir($this->base . '/plugins/legacy-folder');
        file_put_contents($this->base . '/plugins/legacy-folder/plugin.json', json_encode([
            'name' => 'Sample Plugin', 'version' => '1.0.0',
        ]));
        $zip = $this->base . '/data/pkg.zip';
        $this->validPluginZip($zip);
        self::assertFalse($this->marketplace->installFromZip($zip)['success']);
        self::assertDirectoryDoesNotExist($this->base . '/plugins/sample-plugin');
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

    /* -------------------------------------------------------- upload path -- */

    /**
     * Shape a $_FILES-style array around a real temp file, the same way the
     * media library's unit tests feed MediaService::store.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function uploaded(string $path, string $name = 'plugin.zip'): array
    {
        return [
            'name' => $name,
            'type' => 'application/zip',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ];
    }

    public function testUploadPluginInstallsAValidZip(): void
    {
        $zip = $this->base . '/data/upload-me.zip';
        $this->validPluginZip($zip);

        $result = $this->marketplace->uploadPlugin($this->uploaded($zip));

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('sample-plugin', $result['plugin']['id']);
        $this->assertFileExists($this->base . '/plugins/sample-plugin/plugin.json');
        // The staging copy under data/marketplace/ must not linger after install.
        $leftovers = glob($this->base . '/data/marketplace/upload-*.zip') ?: [];
        $this->assertSame([], $leftovers);
    }

    public function testUploadPluginRefusesANonZip(): void
    {
        $path = $this->base . '/data/not-a-zip.txt';
        file_put_contents($path, 'hello');

        $result = $this->marketplace->uploadPlugin($this->uploaded($path, 'plugin.zip'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a ZIP', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/plugins/sample-plugin');
    }

    public function testUploadPluginRefusesWhenNothingWasSent(): void
    {
        $result = $this->marketplace->uploadPlugin([
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No file', $result['error']);
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

    public function testRegistryPluginIdCannotEscapeMarketplaceForTemporaryPackageFile(): void
    {
        $outsidePath = $this->base . '/plugins/victim-2.1.0.zip';
        file_put_contents($outsidePath, 'keep me');

        // The ID is signed and selected by the caller, but is still untrusted
        // path input. The current temp filename embeds it before validation.
        $reg = $this->publishRegistry('../../plugins/victim', 'Victim');

        $result = $this->marketplace->installFromRegistry(
            $reg['registryUrl'],
            $reg['publicKey'],
            '../../plugins/victim'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('keep me', file_get_contents($outsidePath), 'A rejected install must not overwrite or delete a file outside the marketplace directory.');
    }

    public function testSignedManifestVersionCannotEscapeMarketplaceForTemporaryPackageFile(): void
    {
        $outsidePath = $this->base . '/plugins/victim.zip';
        file_put_contents($outsidePath, 'keep me');
        // The old filename begins with `<id>-` before parsing version path
        // separators, so this prefix must exist for the traversal to resolve.
        mkdir($this->base . '/data/marketplace/sample-plugin-..', 0o755, true);

        // The requested plugin id is valid; only the signed version field is
        // hostile. Neither may be incorporated into a filesystem path.
        $reg = $this->publishRegistry(
            'sample-plugin',
            'Sample Plugin',
            static function (array $manifest): array {
                $manifest['version'] = '../../../../plugins/victim';
                return $manifest;
            }
        );

        $result = $this->marketplace->installFromRegistry(
            $reg['registryUrl'],
            $reg['publicKey'],
            'sample-plugin'
        );

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('keep me', file_get_contents($outsidePath), 'A signed version must not overwrite or delete a file outside the marketplace directory.');
    }

    public function testRejectsASignedManifestWithMalformedChecksumWithoutThrowing(): void
    {
        $reg = $this->publishRegistry(
            'sample-plugin',
            'Sample Plugin',
            static function (array $manifest): array {
                $manifest['sha256'] = ['not-a-hash'];
                return $manifest;
            }
        );

        $result = $this->marketplace->installFromRegistry(
            $reg['registryUrl'],
            $reg['publicKey'],
            'sample-plugin'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('checksum', strtolower($result['error']));
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
