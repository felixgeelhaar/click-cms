<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Domain\Update\Release;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Applying a release replaces the running application's own code, so what is
 * pinned here is not "does it work" but "what happens when it goes wrong":
 * a wrong checksum changes nothing, a package that reaches for the site's
 * content is refused, and a failure part-way leaves the install as it was.
 */
final class UpdateInstallerTest extends TestCase
{
    private string $base;
    private string $packages;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-update-' . bin2hex(random_bytes(6));
        $this->packages = $this->base . '-pkg';
        mkdir($this->base, 0o755, true);
        mkdir($this->packages, 0o755, true);

        // A minimal live installation: code that an update replaces, and site
        // data that it must not.
        mkdir($this->base . '/src', 0o755, true);
        file_put_contents($this->base . '/src/App.php', '<?php // version 1');
        mkdir($this->base . '/content/page/en', 0o755, true);
        file_put_contents($this->base . '/content/page/en/home.json', '{"title":"My page"}');
        mkdir($this->base . '/data', 0o755, true);
        file_put_contents($this->base . '/data/settings.json', '{"siteName":"Mine"}');
        mkdir($this->base . '/config', 0o755, true);
        file_put_contents($this->base . '/config/core.json', '{"core":{}}');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
        $this->rrmdir($this->packages);
    }

    /** @param array<string,string> $entries */
    private function makePackage(string $name, array $entries): string
    {
        $path = "$this->packages/$name";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return $path;
    }

    private function releaseFor(string $package, ?string $sha = null): Release
    {
        $release = Release::fromArray([
            'version' => '1.5.0',
            'packageUrl' => 'https://example.com/click-cms-1.5.0.zip',
            'sha256' => $sha ?? hash_file('sha256', $package),
        ]);
        $this->assertNotNull($release);

        return $release;
    }

    private function installer(): UpdateInstaller
    {
        return new UpdateInstaller($this->base, $this->base . '/data/updates');
    }

    /* ----------------------------------------------- telling the truth after -- */

    /**
     * An update that could not be undone must not claim it was.
     *
     * Found by installing over HTTP: the swap failed partway, the response said
     * "the update was rolled back", and the installation was left with new code
     * in src/ and no public/ at all — a site with no document root, told that
     * nothing had changed. The operator's next move after that message is to
     * look somewhere else entirely.
     *
     * Simulated by making the restore impossible: the backup directory is
     * removed before the failing rename, so nothing can be put back.
     */
    public function testAFailedRollbackSaysSoRatherThanClaimingSuccess(): void
    {
        mkdir($this->base . '/public', 0o755, true);
        file_put_contents($this->base . '/public/index.php', '<?php // live');

        // The public directory remains in place. Simulate a failed atomic
        // replacement of its index, followed by a failed restore from backup.
        $package = $this->makePackage('update.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            'click-cms-1.5.0/public/index.php' => '<?php // version 2',
        ]);

        // The shape seen in the wild: public/ is set aside successfully, the
        // incoming one cannot be put in its place, and neither can the original
        // — leaving the site with new code and no public/ at all. Anything
        // moving *to* the live public fails; moving it aside is allowed.
        $live = $this->base . '/public';
        $installer = new class ($this->base, $this->base . '/data/updates', $live) extends UpdateInstaller {
            public function __construct(string $base, string $backups, private readonly string $blocked)
            {
                parent::__construct($base, $backups);
            }

            protected function move(string $from, string $to): bool
            {
                if ($to === $this->blocked . '/index.php'
                    && (str_contains($from, '.update-staging-') || str_contains($from, '/backup-'))) {
                    return false;
                }
                return parent::move($from, $to);
            }
        };

        $result = $installer->install($this->releaseFor($package), $package);

        $this->assertFalse($result['success'], (string) ($result['error'] ?? ''));
        $this->assertStringContainsString('public', (string) $result['error']);
        // The message must name the backup, because a human has to finish the job.
        $this->assertStringContainsString($this->base . '/data/updates', (string) $result['error']);
        $this->assertNotEmpty($result['backup']);
    }

    /* ------------------------------------------------- the site's own rewrite -- */

    /**
     * The file that holds an installation together is not replaced.
     *
     * `public/.htaccess` is where a site says where its own code lives
     * (`SetEnv CLICK_CMS_ROOT`) and what URL prefix it answers on
     * (`RewriteBase`). Both exist *because* an update replaces `public/` — and
     * replacing that file along with the rest takes the site down: it can no
     * longer find its own vendor/, so it 500s, and every clean URL 404s.
     *
     * Worse than a manual mistake, because a security release installs
     * unattended: the site would break itself, overnight, from an update meant
     * to protect it.
     */
    public function testAnUpdateLeavesTheSitesOwnHtaccessInPlace(): void
    {
        mkdir($this->base . '/public', 0o755, true);
        $live = "SetEnv CLICK_CMS_ROOT /home/example/click-cms\nRewriteBase /2026/cms/\nRewriteEngine On\n";
        file_put_contents($this->base . '/public/.htaccess', $live);
        file_put_contents($this->base . '/public/index.php', '<?php // version 1');

        $package = $this->makePackage('update.zip', [
            'click-cms-1.5.0/public/index.php' => '<?php // version 2',
            'click-cms-1.5.0/public/.htaccess' => "RewriteEngine On\nRewriteRule ^(.*)$ index.php [QSA,L]\n",
        ]);
        $result = $this->installer()->install($this->releaseFor($package), $package);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame($live, file_get_contents($this->base . '/public/.htaccess'));
        // The rest of public/ still updates.
        $this->assertSame('<?php // version 2', file_get_contents($this->base . '/public/index.php'));
    }

    public function testSplitPublicRootUpdatesAndRestoresAssetsAndSiteHtaccess(): void
    {
        $public = $this->packages . '/detached-public';
        mkdir($public, 0o755, true);
        file_put_contents($public . '/index.php', '<?php // old public');
        $htaccess = "SetEnv CLICK_CMS_ROOT /srv/cms\nRewriteBase /site/\n";
        file_put_contents($public . '/.htaccess', $htaccess);
        $package = $this->makePackage('split.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // next code',
            'click-cms-1.5.0/public/index.php' => '<?php // next public',
            'click-cms-1.5.0/public/.htaccess' => "RewriteEngine On\n",
        ]);
        $installer = new UpdateInstaller($this->base, $this->base . '/data/updates', $public);

        $result = $installer->install($this->releaseFor($package), $package);
        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame('<?php // next public', file_get_contents($public . '/index.php'));
        $this->assertSame($htaccess, file_get_contents($public . '/.htaccess'));
        $this->assertSame("RewriteEngine On\n", file_get_contents($public . '/.htaccess.dist'));
        $this->assertSame('<?php // old public', file_get_contents($result['backup'] . '/public/index.php'));

        $restored = $installer->restore((string) $result['backup']);
        $this->assertTrue($restored['success'], (string) ($restored['error'] ?? ''));
        $this->assertSame('<?php // old public', file_get_contents($public . '/index.php'));
        $this->assertSame($htaccess, file_get_contents($public . '/.htaccess'));
    }

    public function testConfiguredSymlinkPublicRootIsRefusedWithOrWithoutTrailingSlash(): void
    {
        $public = $this->packages . '/real-public';
        $link = $this->packages . '/public-link';
        mkdir($public, 0o755, true);
        file_put_contents($public . '/index.php', '<?php // site public');
        symlink($public, $link);
        $package = $this->makePackage('symlink-root.zip', ['public/index.php' => '<?php // next public']);
        try {
            foreach ([$link, $link . '/'] as $configured) {
                $installer = new UpdateInstaller($this->base, null, $configured);
                $result = $installer->install($this->releaseFor($package), $package);
                $this->assertFalse($result['success'], 'Symlink root must be refused: ' . $configured);
                $this->assertSame('<?php // site public', file_get_contents($public . '/index.php'));
            }
        } finally {
            unlink($link);
        }
    }

    public function testConfiguredMissingSplitPublicRootFailsClosed(): void
    {
        $package = $this->makePackage('split.zip', ['public/index.php' => '<?php']);
        $installer = new UpdateInstaller($this->base, $this->base . '/data/updates', $this->packages . '/missing');

        $result = $installer->install($this->releaseFor($package), $package);

        $this->assertFalse($result['success'], (string) ($result['error'] ?? ''));
        $this->assertStringContainsString('public root', (string) $result['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
        $this->assertFileDoesNotExist($this->base . '/public/index.php');
    }

    public function testSplitPublicRootHtaccessPreservationFailureRollsBack(): void
    {
        $public = $this->packages . '/detached-public';
        mkdir($public, 0o755, true);
        file_put_contents($public . '/index.php', '<?php // old public');
        file_put_contents($public . '/.htaccess', "RewriteBase /site/\n");
        $package = $this->makePackage('split-rollback.zip', [
            'public/index.php' => '<?php // next public',
            'public/.htaccess' => "RewriteEngine On\n",
            'public/.htaccess.dist/blocked' => 'cannot copy a file over this directory',
        ]);
        $installer = new UpdateInstaller($this->base, $this->base . '/data/updates', $public);

        $result = $installer->install($this->releaseFor($package), $package);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php // old public', file_get_contents($public . '/index.php'));
        $this->assertSame("RewriteBase /site/\n", file_get_contents($public . '/.htaccess'));
    }

    public function testSplitPublicRootSwapFailureRollsBackToCapturedExternalPath(): void
    {
        $public = $this->packages . '/detached-public';
        mkdir($public, 0o755, true);
        file_put_contents($public . '/index.php', '<?php // old public');
        $package = $this->makePackage('split-failure.zip', ['public/index.php' => '<?php // next public']);
        $installer = new class ($this->base, $this->base . '/data/updates', $public) extends UpdateInstaller {
            private bool $failed = false;
            private string $canonicalPublicRoot;

            public function __construct(string $base, string $backups, private readonly string $publicRoot)
            {
                $this->canonicalPublicRoot = (string) realpath($publicRoot);
                parent::__construct($base, $backups, $publicRoot);
            }

            protected function move(string $from, string $to): bool
            {
                if (!$this->failed && $to === $this->canonicalPublicRoot . '/index.php' && str_contains($from, '.update-staging-')) {
                    $this->failed = true;
                    return false;
                }
                return parent::move($from, $to);
            }
        };

        $result = $installer->install($this->releaseFor($package), $package);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php // old public', file_get_contents($public . '/index.php'));
        $this->assertFileDoesNotExist($this->base . '/public/index.php');
    }

    public function testSplitPublicRootRestoreFailureRollsBackToCapturedExternalPath(): void
    {
        $public = $this->packages . '/detached-public';
        mkdir($public, 0o755, true);
        file_put_contents($public . '/index.php', '<?php // current public');
        $backup = $this->base . '/data/updates/backup-split-restore';
        mkdir($backup . '/public', 0o755, true);
        file_put_contents($backup . '/public/index.php', '<?php // saved public');
        $installer = new class ($this->base, $this->base . '/data/updates', $public, $backup) extends UpdateInstaller {
            private bool $failed = false;
            private string $canonicalPublicRoot;

            public function __construct(string $base, string $backups, private readonly string $publicRoot, private readonly string $backup)
            {
                $this->canonicalPublicRoot = (string) realpath($publicRoot);
                parent::__construct($base, $backups, $publicRoot);
            }

            protected function move(string $from, string $to): bool
            {
                if (!$this->failed && $from === $this->backup . '/public' && $to === $this->canonicalPublicRoot) {
                    $this->failed = true;
                    return false;
                }
                return parent::move($from, $to);
            }
        };

        $result = $installer->restore($backup);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php // current public', file_get_contents($public . '/index.php'));
        $this->assertSame('<?php // saved public', file_get_contents($backup . '/public/index.php'));
    }

    /**
     * The incoming version is kept beside it, so an operator can see what
     * changed rather than having to diff against a release archive.
     */
    public function testTheShippedHtaccessIsLeftAlongsideForComparison(): void
    {
        mkdir($this->base . '/public', 0o755, true);
        file_put_contents($this->base . '/public/.htaccess', "RewriteBase /2026/cms/\n");

        $shipped = "RewriteEngine On\n# something new\nRewriteRule ^(.*)$ index.php [QSA,L]\n";
        $package = $this->makePackage('update.zip', [
            'click-cms-1.5.0/public/.htaccess' => $shipped,
            'click-cms-1.5.0/public/index.php' => '<?php',
        ]);
        $this->installer()->install($this->releaseFor($package), $package);

        $this->assertSame($shipped, file_get_contents($this->base . '/public/.htaccess.dist'));
    }

    /** A first install with no .htaccess of its own takes the shipped one. */
    public function testAnInstallWithoutAnHtaccessTakesTheShippedOne(): void
    {
        mkdir($this->base . '/public', 0o755, true);
        $shipped = "RewriteEngine On\nRewriteRule ^(.*)$ index.php [QSA,L]\n";
        $package = $this->makePackage('update.zip', [
            'click-cms-1.5.0/public/.htaccess' => $shipped,
        ]);
        $this->installer()->install($this->releaseFor($package), $package);

        $this->assertSame($shipped, file_get_contents($this->base . '/public/.htaccess'));
        $this->assertFileDoesNotExist($this->base . '/public/.htaccess.dist');
    }

    /* ------------------------------------------------------------ happy path -- */

    public function testInstallsAReleaseAndKeepsABackup(): void
    {
        $pkg = $this->makePackage('ok.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            'click-cms-1.5.0/src/New.php' => '<?php // added',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('<?php // version 2', file_get_contents($this->base . '/src/App.php'));
        $this->assertFileExists($this->base . '/src/New.php');
        // The replaced code is kept, so there is a way back.
        $this->assertFileExists($result['backup'] . '/src/App.php');
        $this->assertSame('<?php // version 1', file_get_contents($result['backup'] . '/src/App.php'));
    }

    public function testAnInstalledUpdateCanBeRestored(): void
    {
        $pkg = $this->makePackage('ok.zip', ['click-cms-1.5.0/src/App.php' => '<?php // version 2']);
        $installer = $this->installer();

        $result = $installer->install($this->releaseFor($pkg), $pkg);
        $this->assertTrue($result['success']);

        $restored = $installer->restore((string) $result['backup']);

        $this->assertTrue($restored['success'], (string) $restored['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testRestoreFailureRestoresLiveDirectoriesAndKeepsBackupSource(): void
    {
        mkdir($this->base . '/public', 0o755, true);
        file_put_contents($this->base . '/public/index.php', '<?php // live public');
        $backup = $this->base . '/data/updates/backup-restore-failure';
        mkdir($backup . '/src', 0o755, true);
        mkdir($backup . '/public', 0o755, true);
        file_put_contents($backup . '/src/App.php', '<?php // saved src');
        file_put_contents($backup . '/public/index.php', '<?php // saved public');

        $installer = new class ($this->base, $this->base . '/data/updates', $backup . '/public', $this->base . '/public') extends UpdateInstaller {
            private bool $failed = false;

            public function __construct(string $base, string $backups, private readonly string $blockedFrom, private readonly string $blockedTo)
            {
                parent::__construct($base, $backups);
            }

            protected function move(string $from, string $to): bool
            {
                if (!$this->failed && $from === $this->blockedFrom && $to === $this->blockedTo) {
                    $this->failed = true;
                    return false;
                }
                return parent::move($from, $to);
            }
        };

        $result = $installer->restore($backup);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
        $this->assertSame('<?php // live public', file_get_contents($this->base . '/public/index.php'));
        $this->assertSame('<?php // saved src', file_get_contents($backup . '/src/App.php'));
        $this->assertSame('<?php // saved public', file_get_contents($backup . '/public/index.php'));
    }

    public function testRestoreReportsAndRetainsRecoveryFilesWhenRollbackAlsoFails(): void
    {
        mkdir($this->base . '/public', 0o755, true);
        file_put_contents($this->base . '/public/index.php', '<?php // live public');
        $backup = $this->base . '/data/updates/backup-restore-double-failure';
        mkdir($backup . '/src', 0o755, true);
        mkdir($backup . '/public', 0o755, true);
        file_put_contents($backup . '/src/App.php', '<?php // saved src');
        file_put_contents($backup . '/public/index.php', '<?php // saved public');

        $installer = new class ($this->base, $this->base . '/data/updates', $backup, $this->base) extends UpdateInstaller {
            private bool $restoreFailed = false;
            private bool $rollbackFailed = false;

            public function __construct(string $base, string $backups, private readonly string $backup, private readonly string $basePath)
            {
                parent::__construct($base, $backups);
            }

            protected function move(string $from, string $to): bool
            {
                if (!$this->restoreFailed && $from === $this->backup . '/public' && $to === $this->basePath . '/public') {
                    $this->restoreFailed = true;
                    return false;
                }
                if (!$this->rollbackFailed && str_starts_with($from, $this->basePath . '/.update-restore-')
                    && str_ends_with($from, '/src') && $to === $this->basePath . '/src') {
                    $this->rollbackFailed = true;
                    return false;
                }

                return parent::move($from, $to);
            }
        };

        $result = $installer->restore($backup);
        $recoveryDirs = glob($this->base . '/.update-restore-*') ?: [];

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('src', (string) $result['error']);
        $this->assertCount(1, $recoveryDirs);
        $this->assertStringContainsString($recoveryDirs[0], (string) $result['error']);
        $this->assertSame('<?php // version 1', file_get_contents($recoveryDirs[0] . '/src/App.php'));
        $this->assertSame('<?php // saved src', file_get_contents($backup . '/src/App.php'));
    }

    public function testRestoreRejectsDirectoriesOutsideTheConfiguredBackupRoot(): void
    {
        $untrusted = $this->packages . '/backup-outside-root';
        mkdir($untrusted . '/src', 0o755, true);
        file_put_contents($untrusted . '/src/App.php', '<?php // attacker controlled');

        $result = $this->installer()->restore($untrusted);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('safe directory', (string) $result['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testFlatPackageWithOnlyASrcDirectoryCanBeInstalled(): void
    {
        $package = $this->makePackage('flat.zip', ['src/App.php' => '<?php // flat version 2']);

        $result = $this->installer()->install($this->releaseFor($package), $package);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame('<?php // flat version 2', file_get_contents($this->base . '/src/App.php'));
    }

    public function testRestoreDoesNotFollowALiveDirectorySymlinkOutsideTheInstallation(): void
    {
        $outside = $this->packages . '/outside';
        mkdir($outside, 0o755, true);
        file_put_contents($outside . '/keep.txt', 'must survive');

        $backup = $this->base . '/data/updates/backup-symlink-test';
        mkdir($backup . '/src', 0o755, true);
        file_put_contents($backup . '/src/App.php', '<?php // saved');

        $this->rrmdir($this->base . '/src');
        symlink($outside, $this->base . '/src');

        $result = $this->installer()->restore($backup);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame('must survive', file_get_contents($outside . '/keep.txt'));
        $this->assertSame('<?php // saved', file_get_contents($this->base . '/src/App.php'));
    }

    /* ------------------------------------------------------------- refusals -- */

    /** The most important test here: a bad checksum must change nothing at all. */
    public function testAChecksumMismatchInstallsNothing(): void
    {
        $pkg = $this->makePackage('bad.zip', ['click-cms-1.5.0/src/App.php' => '<?php // tampered']);
        $wrong = str_repeat('0', 64);

        $result = $this->installer()->install($this->releaseFor($pkg, $wrong), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('checksum', (string) $result['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'), 'nothing may change');
    }

    /**
     * A release package has no business writing the site's pages, uploads or
     * settings. Those entries are dropped rather than the update being refused,
     * so a package that merely ships an example file still installs.
     */
    public function testThePackageCannotOverwriteContentDataOrConfig(): void
    {
        $pkg = $this->makePackage('greedy.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            'click-cms-1.5.0/content/page/en/home.json' => '{"title":"REPLACED"}',
            'click-cms-1.5.0/data/settings.json' => '{"siteName":"REPLACED"}',
            'click-cms-1.5.0/config/core.json' => '{"core":"REPLACED"}',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('{"title":"My page"}', file_get_contents($this->base . '/content/page/en/home.json'));
        $this->assertSame('{"siteName":"Mine"}', file_get_contents($this->base . '/data/settings.json'));
        $this->assertSame('{"core":{}}', file_get_contents($this->base . '/config/core.json'));
    }

    public function testAPackageThatEscapesItsDirectoryIsRefused(): void
    {
        $sentinel = sys_get_temp_dir() . '/click-cms-UPDATE-ESCAPE';
        @unlink($sentinel);

        $pkg = $this->makePackage('evil.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            '../../../../../../../../tmp/click-cms-UPDATE-ESCAPE' => 'pwned',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', (string) $result['error']);
        $this->assertFileDoesNotExist($sentinel);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testASymlinkEntryInThePackageIsRefused(): void
    {
        $package = $this->packages . '/symlink.zip';
        $zip = new ZipArchive();
        $zip->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('click-cms-1.5.0/src/App.php', '<?php // version 2');
        $zip->addFromString('click-cms-1.5.0/src/escape', '../../outside');
        $this->assertTrue($zip->setExternalAttributesName(
            'click-cms-1.5.0/src/escape',
            ZipArchive::OPSYS_UNIX,
            0120777 << 16,
        ));
        $zip->close();

        $result = $this->installer()->install($this->releaseFor($package), $package);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('symlink', strtolower((string) $result['error']));
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testUpdateAndRestoreRefuseWhenTheSharedInstallerLockIsHeld(): void
    {
        $package = $this->makePackage('locked.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
        ]);
        $lockPath = $this->base . '/data/updates/install.lock';
        mkdir(dirname($lockPath), 0o755, true);
        $lock = fopen($lockPath, 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $update = $this->installer()->install($this->releaseFor($package), $package);
            $restore = $this->installer()->restore(dirname($lockPath) . '/backup-existing');

            $this->assertFalse($update['success']);
            $this->assertFalse($restore['success']);
            $this->assertStringContainsString('already in progress', (string) $update['error']);
            $this->assertStringContainsString('already in progress', (string) $restore['error']);
            $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testAnAbsolutePathEntryIsRefused(): void
    {
        $pkg = $this->makePackage('abs.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php',
            '/etc/click-cms-should-never-exist' => 'x',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', (string) $result['error']);
    }

    public function testAPackageThatShipsNoKnownDirectoryChangesNothing(): void
    {
        $pkg = $this->makePackage('empty.zip', ['click-cms-1.5.0/README.md' => '# notes']);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no installable', (string) $result['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testAnUnopenablePackageIsReportedNotIgnored(): void
    {
        $notAZip = "$this->packages/broken.zip";
        file_put_contents($notAZip, 'this is not a zip file');

        $result = $this->installer()->install($this->releaseFor($notAZip), $notAZip);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
