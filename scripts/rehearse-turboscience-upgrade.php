#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reproducible, isolated upgrade rehearsal against TurboScience's
 * production configuration and exported Bludit content. No site credentials
 * are read or used. Signed feeds and API requests use loopback only.
 *
 * Usage:
 *   php scripts/rehearse-turboscience-upgrade.php <cms-repo> <website-repo>
 */

$cmsRepo = realpath($argv[1] ?? dirname(__DIR__)) ?: throw new RuntimeException('CMS repo not found');
$siteRepo = isset($argv[2]) ? realpath($argv[2]) : false;
$siteRepo = $siteRepo
    ?: throw new RuntimeException('Website repo not found');
$python = getenv('CLICK_REHEARSAL_PYTHON') ?: trim(run('command -v python3'));
if (!getenv('CLICK_REHEARSAL_PYTHON') && $python !== ''
    && version_compare(preg_replace('/^[^0-9]+/', '', trim(run(escapeshellarg($python) . ' --version 2>&1'))), '3.10', '<')
    && is_executable('/opt/homebrew/bin/python3')) {
    $python = '/opt/homebrew/bin/python3';
}
$node = getenv('CLICK_REHEARSAL_NODE') ?: trim(run('command -v node'));
if ($python === '' || $node === '') throw new RuntimeException('Python 3 and Node.js are required (or set CLICK_REHEARSAL_PYTHON / CLICK_REHEARSAL_NODE)');
if (version_compare(preg_replace('/^[^0-9]+/', '', trim(run(escapeshellarg($python) . ' --version 2>&1'))), '3.10', '<')) throw new RuntimeException('Python 3.10 or later is required for the site migration scripts');
if (version_compare(ltrim(trim(run(escapeshellarg($node) . ' --version')), 'v'), '22.13.0', '<')) throw new RuntimeException('Node.js 22.13 or later is required for built-in TypeScript stripping');
$scratch = sys_get_temp_dir() . '/turboscience-upgrade-' . bin2hex(random_bytes(5));
mkdir($scratch, 0700, true);
$publicPath = $scratch . '/public-webroot';
mkdir($publicPath, 0700, true);
$failures = [];
$checks = 0;
$diagnostics = [];

function check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "ok: {$message}\n");
    }
}

function run(string $cmd, ?string $cwd = null, array $env = []): string
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, $cwd, $env === [] ? null : array_merge($_ENV, $env));
    if (!is_resource($proc)) throw new RuntimeException("Could not run: {$cmd}");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) throw new RuntimeException("Command failed ({$code}): {$cmd}\n{$stdout}\n{$stderr}");
    return $stdout;
}

function copyTree(string $src, string $dst): void
{
    if (!is_dir($src)) return;
    @mkdir($dst, 0755, true);
    foreach (new DirectoryIterator($src) as $item) {
        if ($item->isDot()) continue;
        $from = $item->getPathname(); $to = $dst . '/' . $item->getFilename();
        if ($item->isDir() && !$item->isLink()) copyTree($from, $to);
        elseif ($item->isFile() && !$item->isLink()) copy($from, $to);
    }
}

function zipTree(string $source, string $zipFile): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create package');
    foreach (['src', 'public', 'plugins', 'vendor', 'bin'] as $directory) {
        $path = $source . '/' . $directory;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $relative = $directory . '/' . substr($file->getPathname(), strlen($path) + 1);
            $zip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        }
    }
    $zip->close();
}

function hashTree(string $path): string
{
    $rows = [];
    if (!is_dir($path)) return '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile() && !$file->isLink()) $rows[substr($file->getPathname(), strlen($path) + 1)] = hash_file('sha256', $file->getPathname());
    ksort($rows);
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

function hashSiteData(string $path): string
{
    // Updates intentionally write a rollback backup under data/updates. Compare
    // the site's pre-existing data, while excluding that updater-owned ledger.
    $rows = [];
    if (!is_dir($path)) return '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($path) + 1));
        if ($relative === 'updates' || str_starts_with($relative, 'updates/')) continue;
        $rows[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($rows);
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

function setSiteUpdaterConfig(string $coreFile, string $feedUrl, string $publicKey, string $publicRoot): void
{
    $core = json_decode((string) file_get_contents($coreFile), true, 512, JSON_THROW_ON_ERROR);
    $core['core']['updates'] = [
        'policy' => 'minor', 'feedUrl' => $feedUrl, 'allowPreRelease' => false,
        'publicKey' => $publicKey, 'publicRoot' => $publicRoot,
    ];
    file_put_contents($coreFile, json_encode($core, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function seedTurboScience(string $cmsRepo, string $siteRepo, string $root): array
{
    $fixture = $cmsRepo . '/scripts/updates/turboscience-fixture.py';
    if (!is_file($fixture)) throw new RuntimeException('TurboScience fixture seeder not found');
    global $python;
    $command = 'PYTHONPYCACHEPREFIX=' . escapeshellarg(sys_get_temp_dir() . '/click-pycache') . ' ' . escapeshellarg($python) . ' -B '
        . escapeshellarg($fixture) . ' --site ' . escapeshellarg($siteRepo) . ' --root ' . escapeshellarg($root);
    return json_decode(run($command), true, 512, JSON_THROW_ON_ERROR);
}

function makeCandidateTree(string $repo, string $destination, string $version): void
{
    mkdir($destination, 0755, true);
    foreach (['src', 'public', 'plugins', 'vendor', 'bin'] as $directory) {
        copyTree($repo . '/' . $directory, $destination . '/' . $directory);
    }
    $application = $destination . '/src/Core/Application.php';
    $source = (string) file_get_contents($application);
    $updated = preg_replace("/public const VERSION = '[^']+';/", "public const VERSION = '{$version}';", $source, 1, $count);
    if ($count !== 1 || !is_string($updated)) throw new RuntimeException('Could not set rehearsal-only package version');
    file_put_contents($application, $updated);
}

function publishSignedFeed(string $directory, string $packagePath, string $feedUrl, string $version, int $sequence, $key, bool $badChecksum = false): void
{
    $publishedPackage = $directory . '/' . basename($packagePath);
    if (realpath($packagePath) !== realpath($publishedPackage) && !copy($packagePath, $publishedPackage)) {
        throw new RuntimeException('Could not copy package into offline feed webroot');
    }
    $body = json_encode([
        'sequence' => $sequence,
        'expires' => gmdate('c', time() + 3600),
        'releases' => [[
            'version' => $version,
            'packageUrl' => $feedUrl . '/' . basename($publishedPackage),
            'sha256' => $badChecksum ? str_repeat('0', 64) : hash_file('sha256', $publishedPackage),
            'security' => true,
            'notes' => 'REHEARSAL ONLY; generated locally, not a published release',
            'requiresPhp' => '8.1',
        ]],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/releases.json', $body);
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);
    file_put_contents($directory . '/releases.json.sig', base64_encode($signature));
}

function runCli(string $root, string $command): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, 'bin/click-update.php', '--force'], $spec, $pipes, $root, array_merge($_ENV, ['CLICK_CMS_ROOT' => $root]));
    if (!is_resource($proc)) throw new RuntimeException('Could not start isolated updater CLI');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($proc);
    return [$status, $stdout, $stderr];
}

function writePrefixRouter(string $filename, string $publicRoot): void
{
    $source = '<?php\n'
        . '$prefix="/2026/cms2"; $root=' . var_export($publicRoot, true) . ';\n'
        . '$uri=(string)($_SERVER["REQUEST_URI"]??"/"); $path=(string)(parse_url($uri,PHP_URL_PATH)??"/");\n'
        . '$_SERVER["SCRIPT_NAME"]=$prefix."/index.php"; $_SERVER["PHP_SELF"]=$prefix."/index.php";\n'
        . 'if (!str_starts_with($path,$prefix)) { http_response_code(404); exit; }\n'
        . '$relative=substr($path,strlen($prefix)); $target=realpath($root."/".ltrim($relative,"/"));\n'
        . 'if ($target!==false && str_starts_with($target,realpath($root).DIRECTORY_SEPARATOR) && is_file($target)) {\n'
        . ' $types=["css"=>"text/css","js"=>"application/javascript","png"=>"image/png","jpg"=>"image/jpeg","jpeg"=>"image/jpeg","svg"=>"image/svg+xml","webp"=>"image/webp","mp4"=>"video/mp4"]; $ext=strtolower(pathinfo($target,PATHINFO_EXTENSION)); if(isset($types[$ext]))header("Content-Type: ".$types[$ext]); readfile($target); exit; }\n'
        . 'require $root."/index.php";';
    file_put_contents($filename, str_replace('\\n', "\n", $source));
}

function startHttpServer(string $root, string $publicRoot, string $router, int $port): array
{
    writePrefixRouter($router, $publicRoot);
    $spec = [0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']];
    $proc = proc_open([PHP_BINARY, '-d', 'disable_functions=proc_open,proc_close,exec,shell_exec,system,passthru,popen', '-S', "127.0.0.1:{$port}", '-t', $publicRoot, $router], $spec, $pipes, $root, array_merge($_ENV, ['CLICK_CMS_ROOT'=>$root]));
    if (!is_resource($proc)) throw new RuntimeException('Could not start isolated PHP server');
    fclose($pipes[0]);
    $ready = false;
    for ($i=0; $i<40; $i++) { $socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.1); if ($socket) { fclose($socket); $ready=true; break; } usleep(50000); }
    if (!$ready) { proc_terminate($proc); proc_close($proc); throw new RuntimeException('Isolated PHP server did not listen on loopback'); }
    return [$proc, $pipes];
}

function stopHttpServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

function runSpaProbe(string $script, string $siteRepo, int $port): array
{
    global $node;
    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' --site ' . escapeshellarg($siteRepo)
        . ' --base ' . escapeshellarg("http://127.0.0.1:{$port}/2026/cms2");
    return json_decode(run($command), true, 512, JSON_THROW_ON_ERROR);
}

function appVersion(string $root): string
{
    preg_match("/public const VERSION = '([^']+)';/", (string) file_get_contents($root . '/src/Core/Application.php'), $matches);
    return (string) ($matches[1] ?? '');
}

function packageFromTag(string $repo, string $tag, string $dest): void
{
    mkdir($dest, 0755, true);
    run('git archive ' . escapeshellarg($tag) . ' | tar -x -C ' . escapeshellarg($dest), $repo);
}

function installerChild(string $root, string $package, string $publicRoot, string $version, bool $failPublicSwap = false): array
{
    $php = <<<'PHP'
<?php
$root=$argv[1]; $package=$argv[2]; $publicRoot=$argv[3]; $version=$argv[4];
spl_autoload_register(static function($class) use ($root) {
  $prefix='Click\\Cms\\'; if (!str_starts_with($class,$prefix)) return;
  $file=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
  if (is_file($file)) require $file;
});
$release=Click\Cms\Domain\Update\Release::fromArray([
 'version'=>$version,'packageUrl'=>'http://127.0.0.1/rehearsal.zip',
 'sha256'=>hash_file('sha256',$package),'security'=>true,'notes'=>'offline rehearsal','requiresPhp'=>'8.1'
]);
$installer = ($argv[5] ?? '') === 'fail-public-swap'
    ? new class($root, $root.'/data/updates', $publicRoot) extends Click\Cms\Application\Update\UpdateInstaller {
        private bool $failed = false;
        private string $canonicalPublic;
        public function __construct(string $root, string $state, string $public) {
            $this->canonicalPublic = (string) realpath($public);
            parent::__construct($root, $state, $public);
        }
        protected function move(string $from, string $to): bool {
            if (!$this->failed && $to === $this->canonicalPublic . '/index.php' && str_contains($from, '.update-staging-')) {
                $this->failed = true;
                return false;
            }
            return parent::move($from, $to);
        }
    }
    : new Click\Cms\Application\Update\UpdateInstaller($root, $root.'/data/updates', $publicRoot);
$result=$installer->install($release,$package);
echo json_encode($result,JSON_THROW_ON_ERROR);
if (!($result['success']??false) && ($argv[5] ?? '') !== 'fail-public-swap') exit(1);
PHP;
    $out = run(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(substr($php, 6)) . ' ' . implode(' ', array_map('escapeshellarg', [$root, $package, $publicRoot, $version, $failPublicSwap ? 'fail-public-swap' : ''])));
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

/** Stop the installed updater immediately after it moves vendor into backup. */
function interruptInstallerChild(string $root, string $package, string $public): int
{
    $code = <<<'PHP'
$root=$argv[1]; $package=$argv[2]; $public=$argv[3];
spl_autoload_register(static function($class) use($root) {
    $prefix='Click\\Cms\\';
    if (str_starts_with($class,$prefix)) require $root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
});
$release=Click\Cms\Domain\Update\Release::fromArray(['version'=>'1.8.5','packageUrl'=>'http://127.0.0.1/rehearsal.zip','sha256'=>hash_file('sha256',$package)]);
$installer=new class($root,null,$public) extends Click\Cms\Application\Update\UpdateInstaller {
    protected function move(string $from,string $to):bool {
        $ok=parent::move($from,$to);
        if ($ok && basename($from)==='vendor' && str_contains($to,'/backup-')) exit(137);
        return $ok;
    }
};
$installer->install($release,$package);
exit(1);
PHP;
    $process=proc_open([PHP_BINARY,'-r',$code,$root,$package,$public], [0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated interruption probe');
    fclose($pipes[0]);
    return proc_close($process);
}

function restoreChild(string $root, string $backup, string $publicRoot): array
{
    $php = <<<'PHP'
<?php
$root=$argv[1]; $backup=$argv[2]; $publicRoot=$argv[3];
spl_autoload_register(static function($class) use ($root) {
  $prefix='Click\\Cms\\'; if (!str_starts_with($class,$prefix)) return;
  $file=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
  if (is_file($file)) require $file;
});
$installer=new Click\Cms\Application\Update\UpdateInstaller($root, $root.'/data/updates', $publicRoot);
$result=$installer->restore($backup);
echo json_encode($result,JSON_THROW_ON_ERROR);
if (!($result['success']??false)) exit(1);
PHP;
    $out = run(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(substr($php, 6)) . ' ' . implode(' ', array_map('escapeshellarg', [$root, $backup, $publicRoot])));
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

try {
    foreach (['v1.4.0', 'v1.8.0'] as $tag) check(trim(run('git rev-parse --verify ' . escapeshellarg($tag), $cmsRepo)) !== '', "local genuine tag {$tag} resolves");
    check(is_file($siteRepo . '/cms/config/core.json'), 'TurboScience core config is available');
    check(is_dir($siteRepo . '/cms/config/sections'), 'TurboScience section configuration is available');
    check(is_file($siteRepo . '/bludit-content-export/2026-07-26/export.json'), 'TurboScience exported content is available');

    $install = $scratch . '/install';
    packageFromTag($cmsRepo, 'v1.4.0', $install);
    @mkdir($install . '/content', 0755, true); @mkdir($install . '/data', 0755, true); @mkdir($install . '/config', 0755, true);
    @mkdir($publicPath, 0755, true);
    copyTree($install . '/public', $publicPath);
    copy($siteRepo . '/cms/config/core.json', $install . '/config/core.json');
    copyTree($siteRepo . '/cms/config/sections', $install . '/config/sections');
    $fixtureManifest = seedTurboScience($cmsRepo, $siteRepo, $install);
    check(($fixtureManifest['documents'] ?? 0) >= 16 && ($fixtureManifest['media'] ?? 0) >= 5, 'site migrators reconstruct localized TurboScience pages, menus, strings, and media');
    // Synthetic operator state proves the installer preserves data without
    // copying any production credentials, cookies, .env, or live database.
    file_put_contents($install . '/data/rehearsal-state.json', json_encode(['admin' => 'synthetic-rehearsal-only'], JSON_THROW_ON_ERROR));

    $preserved = [hashTree($install . '/content'), hashSiteData($install . '/data'), hashTree($install . '/config')];
    foreach ([['v1.4.0', 'v1.8.0'], ['v1.8.0', 'candidate']] as [$from, $to]) {
        fwrite(STDOUT, "\nTransition {$from} -> {$to}\n");
        $pkgDir = $scratch . '/package-' . str_replace('.', '-', $to);
        if ($to === 'candidate') {
            @mkdir($pkgDir, 0755, true);
            foreach (['src', 'public', 'plugins', 'vendor', 'bin'] as $d) copyTree($cmsRepo . '/' . $d, $pkgDir . '/' . $d);
            $version = '1.8.0';
        } else {
            packageFromTag($cmsRepo, $to, $pkgDir);
            $version = substr($to, 1);
        }
        $zipFile = $scratch . '/' . $to . '.zip'; zipTree($pkgDir, $zipFile);
        // Use the staged version's own PHP updater classes in a fresh process,
        // avoiding class or opcode state carried across releases.
        $result = installerChild($install, $zipFile, $publicPath, $version);
        check(($result['success'] ?? false) === true, "{$from} installer applies {$to} package");
        $hashers = [static fn() => hashTree($install . '/content'), static fn() => hashSiteData($install . '/data'), static fn() => hashTree($install . '/config')];
        foreach (['content', 'data', 'config'] as $i => $p) check($hashers[$i]() === $preserved[$i], "{$p}/ site files survive {$from} -> {$to} unchanged (updater backups excluded)");
        check(is_file($publicPath . '/index.php'), "detached public webroot remains populated after {$to}");
        check(is_dir($install . '/src'), "CMS code tree is present after {$to}");
    }

    // Signed-feed follow-on: this starts from a second, pristine v1.4.0 site,
    // serves a synthetic signed feed on loopback, and invokes each installed
    // version's own updater CLI in a separate PHP process. The local candidate
    // version labels below are rehearsal metadata only, never published.
    $signedRoot = $scratch . '/signed-install';
    packageFromTag($cmsRepo, 'v1.4.0', $signedRoot);
    copyTree($cmsRepo . '/vendor', $signedRoot . '/vendor');
    foreach (['content', 'data', 'config'] as $dir) @mkdir($signedRoot . '/' . $dir, 0755, true);
    copy($siteRepo . '/cms/config/core.json', $signedRoot . '/config/core.json');
    copyTree($siteRepo . '/cms/config/sections', $signedRoot . '/config/sections');
    $signedFixtureManifest = seedTurboScience($cmsRepo, $siteRepo, $signedRoot);
    check(($signedFixtureManifest['documents'] ?? 0) >= 16, 'signed-feed site uses same real localized export fixture');
    file_put_contents($signedRoot . '/data/rehearsal-state.json', '{"admin":"synthetic-rehearsal-only"}');
    $signedPublic = $scratch . '/signed-public'; copyTree($signedRoot . '/public', $signedPublic);
    $initialExternalHash = hashTree($signedPublic);
    $signedDataBefore = hashSiteData($signedRoot . '/data');
    $feedDir = $scratch . '/signed-feed'; @mkdir($feedDir, 0755, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) throw new RuntimeException('Synthetic feed key generation failed');
    $details = openssl_pkey_get_details($key); $publicKey = $details['key'];
    $feedPort = random_int(30000, 49000);
    $feedProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$feedPort}", '-t', $feedDir], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $feedPipes, $feedDir, $_ENV);
    if (!is_resource($feedProc)) throw new RuntimeException('Could not start loopback signed-feed server');
    fclose($feedPipes[0]);
    $feedUrl = "http://127.0.0.1:{$feedPort}";
    try {
        $feedReady = false;
        for ($i=0; $i<40; $i++) { $s=@fsockopen('127.0.0.1',$feedPort,$errno,$errstr,0.1); if ($s) { fclose($s); $feedReady=true; break; } usleep(50000); }
        check($feedReady, 'offline signed feed is served on loopback');

        $candidate181 = $scratch . '/candidate-1.8.1'; makeCandidateTree($cmsRepo, $candidate181, '1.8.1');
        $package181 = $scratch . '/candidate-1.8.1.zip'; zipTree($candidate181, $package181);
        setSiteUpdaterConfig($signedRoot . '/config/core.json', $feedUrl . '/releases.json', $publicKey, $signedPublic);
        publishSignedFeed($feedDir, $package181, $feedUrl, '1.8.1', 1, $key);
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $feedBody = (string) file_get_contents($feedDir . '/releases.json');
        openssl_sign($feedBody, $wrongSignature, $wrongKey, OPENSSL_ALGO_SHA256);
        file_put_contents($feedDir . '/releases.json.sig', base64_encode($wrongSignature));
        $legacySourceBefore = hashTree($signedRoot . '/src');
        [$badStatus, $badOut, $badErr] = runCli($signedRoot, 'legacy-bad-signature');
        $diagnostics[] = ['step'=>'legacy-bad-signature', 'status'=>$badStatus, 'stdout'=>$badOut, 'stderr'=>$badErr];
        check($badStatus !== 0 && str_contains($badOut . $badErr, 'signature') && hashTree($signedRoot . '/src') === $legacySourceBefore,
            'genuine v1.4.0 CLI rejects a wrongly signed feed without replacing code');
        publishSignedFeed($feedDir, $package181, $feedUrl, '1.8.1', 1, $key);
        [$status, $stdout, $stderr] = runCli($signedRoot, 'legacy');
        $diagnostics[] = ['step' => 'legacy-cli', 'status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
        check($status === 0 && str_contains($stdout, 'Installed 1.8.1'), 'genuine v1.4.0 CLI verifies and installs signed local candidate package');
        $legacyWebrootHash = hashTree($signedPublic);
        check($legacyWebrootHash === $initialExternalHash, 'legacy v1.4.0 CLI leaves detached webroot untouched (known bootstrap limitation)');
        check(hashSiteData($signedRoot . '/data') === $signedDataBefore, 'signed v1.4.0 update preserves site data and exported content');

        // Repeat signature rejection using the newly installed candidate CLI.
        $signedCore = json_decode((string) file_get_contents($signedRoot . '/config/core.json'), true, 512, JSON_THROW_ON_ERROR);
        $signedCore['core']['basePath'] = '/2026/cms2';
        file_put_contents($signedRoot . '/config/core.json', json_encode($signedCore, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $candidate182 = $scratch . '/candidate-1.8.2'; makeCandidateTree($cmsRepo, $candidate182, '1.8.2');
        $package182 = $scratch . '/candidate-1.8.2.zip'; zipTree($candidate182, $package182);
        publishSignedFeed($feedDir, $package182, $feedUrl, '1.8.2', 2, $key);
        $feedBody = (string) file_get_contents($feedDir . '/releases.json');
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_sign($feedBody, $wrongSignature, $wrongKey, OPENSSL_ALGO_SHA256);
        file_put_contents($feedDir . '/releases.json.sig', base64_encode($wrongSignature));
        $candidateCodeBeforeBadSignature = hashTree($signedRoot . '/src');
        $candidateContentBeforeBadSignature = hashTree($signedRoot . '/content');
        $candidatePublicBeforeBadSignature = hashTree($signedPublic);
        [$badSignatureStatus, $badSignatureOut, $badSignatureErr] = runCli($signedRoot, 'bad-signature');
        $diagnostics[] = ['step' => 'bad-signature-cli', 'status' => $badSignatureStatus, 'stdout' => $badSignatureOut, 'stderr' => $badSignatureErr];
        check($badSignatureStatus !== 0 && appVersion($signedRoot) === '1.8.1', 'candidate CLI rejects a feed signed by the wrong key');
        check(hashTree($signedRoot . '/src') === $candidateCodeBeforeBadSignature
            && hashTree($signedRoot . '/content') === $candidateContentBeforeBadSignature
            && hashTree($signedPublic) === $candidatePublicBeforeBadSignature,
            'wrong-signature rejection leaves code, content, and public tree unchanged');
        openssl_sign($feedBody, $goodSignature, $key, OPENSSL_ALGO_SHA256);
        file_put_contents($feedDir . '/releases.json.sig', base64_encode($goodSignature));

        $signedCore = json_decode((string) file_get_contents($signedRoot . '/config/core.json'), true, 512, JSON_THROW_ON_ERROR);
        $signedCore['core']['basePath'] = '/2026/cms2';
        file_put_contents($signedRoot . '/config/core.json', json_encode($signedCore, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $spaPort = random_int(19000, 39000);
        $spaRouter = $scratch . '/signed-router.php';
        [$spaServer] = startHttpServer($signedRoot, $signedPublic, $spaRouter, $spaPort);
        try {
            $spaBefore = runSpaProbe($cmsRepo . '/scripts/updates/turboscience-spa-probe.mjs', $siteRepo, $spaPort);
            check(count($spaBefore) === 2, 'real TurboScience TypeScript clients pass against reconstructed German and English content before candidate upgrade');
        } finally { stopHttpServer($spaServer); }

        // The historical updater predates detached publicRoot support. Record
        // the live split-root limitation explicitly before the candidate CLI
        // applies the next signed release with the new setting.
        $externalBefore = hashTree($signedPublic);
        check(is_file($signedPublic . '/index.php'), 'detached webroot remains serviceable across the legacy bootstrap step');
        $candidateContentBefore = hashTree($signedRoot . '/content');
        $candidateDataBefore = hashSiteData($signedRoot . '/data');
        $candidateConfigBefore = hashTree($signedRoot . '/config');
        $sourceBeforeRollbackCandidate = hashTree($signedRoot . '/src');
        $siteHtaccess = "# synthetic isolated TurboScience host settings\nSetEnv CLICK_CMS_ROOT {$signedRoot}\nRewriteBase /2026/cms2/\n";
        file_put_contents($signedPublic . '/.htaccess', $siteHtaccess);
        $publicBeforeCandidate = hashTree($signedPublic);
        [$status, $stdout, $stderr] = runCli($signedRoot, 'candidate');
        $diagnostics[] = ['step' => 'candidate-cli', 'status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
        check($status === 0 && str_contains($stdout, 'Installed 1.8.2'), 'candidate CLI consumes monotonic signed feed and updates detached public root');
        check(hashTree($signedPublic) !== $externalBefore, 'candidate update replaces detached public assets');
        check(file_get_contents($signedPublic . '/.htaccess') === $siteHtaccess, 'candidate update preserves site-owned .htaccess bytes');
        check(hashTree($signedRoot . '/content') === $candidateContentBefore
            && hashSiteData($signedRoot . '/data') === $candidateDataBefore
            && hashTree($signedRoot . '/config') === $candidateConfigBefore,
            'candidate CLI update preserves content, media, data, and configuration');
        [$spaServer] = startHttpServer($signedRoot, $signedPublic, $spaRouter, $spaPort);
        try {
            $spaAfter = runSpaProbe($cmsRepo . '/scripts/updates/turboscience-spa-probe.mjs', $siteRepo, $spaPort);
            check($spaBefore === $spaAfter, 'real TurboScience TypeScript client snapshots match before and after signed candidate upgrade');
        } finally { stopHttpServer($spaServer); }

        preg_match('/Backup:\s*(\S+)/', $stdout, $backupMatch);
        check(isset($backupMatch[1]) && is_dir($backupMatch[1]), 'candidate update reports a restorable backup');
        if (isset($backupMatch[1]) && is_dir($backupMatch[1])) {
            $restoreDataBefore = hashSiteData($signedRoot . '/data');
            $restore = restoreChild($signedRoot, $backupMatch[1], $signedPublic);
            check(($restore['success'] ?? false) === true, 'candidate installer restores the signed update backup');
            check(hashTree($signedRoot . '/src') === $sourceBeforeRollbackCandidate
                && hashTree($signedPublic) === $publicBeforeCandidate,
                'backup restore returns source and detached public tree hashes, including exact .htaccess, to pre-update values');
            check(file_get_contents($signedPublic . '/.htaccess') === $siteHtaccess, 'backup restore preserves exact site-owned .htaccess bytes');
            check(hashTree($signedRoot . '/content') === $candidateContentBefore
                && hashSiteData($signedRoot . '/data') === $restoreDataBefore
                && hashTree($signedRoot . '/config') === $candidateConfigBefore,
                'backup restore leaves content, data, and configuration unchanged');
            [$restoreServer] = startHttpServer($signedRoot, $signedPublic, $spaRouter, $spaPort);
            try {
                $spaRestored = runSpaProbe($cmsRepo . '/scripts/updates/turboscience-spa-probe.mjs', $siteRepo, $spaPort);
                check($spaBefore === $spaRestored, 'real TurboScience client snapshots still pass after backup restore');
            } finally { stopHttpServer($restoreServer); }
        }

        // Inject one failed move inside this scratch installation only.
        // The installed updater must restore both code and detached public files.
        $candidate183 = $scratch . '/candidate-1.8.3'; makeCandidateTree($cmsRepo, $candidate183, '1.8.3');
        $package183 = $scratch . '/candidate-1.8.3.zip'; zipTree($candidate183, $package183);
        $beforeFailure = [hashTree($signedRoot . '/src'), hashTree($signedPublic), hashTree($signedRoot . '/content'), hashSiteData($signedRoot . '/data'), hashTree($signedRoot . '/config')];
        $failedInstall = installerChild($signedRoot, $package183, $signedPublic, '1.8.3', true);
        check(($failedInstall['success'] ?? true) === false && str_contains($failedInstall['error'] ?? '', 'rolled back'), 'injected detached-public swap failure reports successful rollback');
        check($beforeFailure === [hashTree($signedRoot . '/src'), hashTree($signedPublic), hashTree($signedRoot . '/content'), hashSiteData($signedRoot . '/data'), hashTree($signedRoot . '/config')], 'failed swap restores code and public assets and preserves all site files');
        $sourceBeforeBad = hashTree($signedRoot . '/src');
        $contentBeforeBad = hashTree($signedRoot . '/content');
        $dataBeforeBad = hashSiteData($signedRoot . '/data');
        $configBeforeBad = hashTree($signedRoot . '/config');
        $publicBeforeBad = hashTree($signedPublic);
        publishSignedFeed($feedDir, $package183, $feedUrl, '1.8.4', 4, $key, true);
        [$status, $stdout, $stderr] = runCli($signedRoot, 'bad-checksum');
        $diagnostics[] = ['step' => 'bad-checksum-cli', 'status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
        check($status !== 0 && str_contains($stdout . $stderr, 'checksum'), 'signed bad-checksum package is rejected by installer');
        check(hashTree($signedRoot . '/src') === $sourceBeforeBad && hashTree($signedRoot . '/content') === $contentBeforeBad
            && hashSiteData($signedRoot . '/data') === $dataBeforeBad
            && hashTree($signedRoot . '/config') === $configBeforeBad
            && hashTree($signedPublic) === $publicBeforeBad, 'bad-checksum rejection leaves content, data, config, and public tree unchanged');
        publishSignedFeed($feedDir, $package183, $feedUrl, '1.8.3', 5, $key);
        [$finalStatus, $finalOut, $finalErr] = runCli($signedRoot, 'final-update');
        check($finalStatus === 0 && str_contains($finalOut, 'Installed 1.8.3'), 'restored site can upgrade again through the signed CLI');

        // The decisive shared-hosting path: no CLI invocation, shell, subprocess
        // or loopback callback inside the CMS. An ordinary API read is the clock.
        $candidate184 = $scratch . '/candidate-1.8.4'; makeCandidateTree($cmsRepo, $candidate184, '1.8.4');
        $package184 = $scratch . '/candidate-1.8.4.zip'; zipTree($candidate184, $package184);
        publishSignedFeed($feedDir, $package184, $feedUrl, '1.8.4', 6, $key);
        @unlink($signedRoot . '/data/updates/web/schedule.json');
        $beforeWeb = [hashTree($signedRoot . '/content'), hashSiteData($signedRoot . '/data'), hashTree($signedRoot . '/config')];
        [$webWorker] = startHttpServer($signedRoot, $signedPublic, $spaRouter, $spaPort);
        try {
            $ctx = stream_context_create(['http' => ['ignore_errors'=>true, 'timeout'=>120]]);
            $body = file_get_contents("http://127.0.0.1:{$spaPort}/2026/cms2/api/pages/home?locale=de", false, $ctx);
            check(str_contains($http_response_header[0] ?? '', '503') && appVersion($signedRoot) === '1.8.4', 'ordinary API traffic installs the signed stable release with shell functions disabled');
            check($beforeWeb === [hashTree($signedRoot . '/content'), hashSiteData($signedRoot . '/data'), hashTree($signedRoot . '/config')], 'web-triggered installation preserves content, media, settings and site data');
            $afterWeb = runSpaProbe($cmsRepo . '/scripts/updates/turboscience-spa-probe.mjs', $siteRepo, $spaPort);
            check($afterWeb === $spaBefore, 'next HTTP requests load upgraded code and unchanged real TurboScience client results');
            check(is_file($signedRoot . '/data/updates/web/schedule.json'), 'web scheduler persists its daily check without cron');
            check(!is_file($signedRoot . '/data/updates/swap-journal.json'), 'successful web update leaves no pending recovery transaction');
            $beforeInterruption = [hashTree($signedRoot . '/src'), hashTree($signedRoot . '/vendor'), hashTree($signedPublic)];
            $interrupted = interruptInstallerChild($signedRoot, $package184, $signedPublic);
            check($interrupted === 137 && !is_dir($signedRoot . '/vendor') && is_file($signedRoot . '/data/updates/swap-journal.json'), 'abruptly stopped installed updater leaves a durable journal with vendor unavailable');
            $recoveryBody = file_get_contents("http://127.0.0.1:{$spaPort}/2026/cms2/api/pages/home?locale=de", false, $ctx);
            check(str_contains($http_response_header[0] ?? '', '503') && str_contains((string)$recoveryBody, 'recovery finished'), 'ordinary HTTP request recovers interrupted upgrade before vendor autoload');
            check($beforeInterruption === [hashTree($signedRoot . '/src'), hashTree($signedRoot . '/vendor'), hashTree($signedPublic)] && !is_file($signedRoot . '/data/updates/swap-journal.json'), 'interrupted update recovery restores exact source, vendor and detached public hashes');
            $recoveredClients = runSpaProbe($cmsRepo . '/scripts/updates/turboscience-spa-probe.mjs', $siteRepo, $spaPort);
            check($recoveredClients === $spaBefore, 'both real TurboScience language clients pass after interrupted-update recovery');

        } finally { stopHttpServer($webWorker); }

    } finally {
        proc_terminate($feedProc); foreach ([1,2] as $n) if (isset($feedPipes[$n])) fclose($feedPipes[$n]); proc_close($feedProc);
    }

    // Check configuration contracts and actual HTTP delivery from a fresh PHP
    // web process. API calls stay on loopback and use no credentials.
    $core = json_decode(file_get_contents($signedRoot . '/config/core.json'), true, 512, JSON_THROW_ON_ERROR);
    check(($core['core']['languages']['default'] ?? '') === 'de' && ($core['core']['languages']['available'] ?? []) === ['de', 'en'], 'German default and English locale are preserved');
    check(($core['core']['storage']['backend'] ?? '') === 'json' && ($core['core']['graphql']['enabled'] ?? true) === false, 'production JSON/REST configuration is preserved');
    $router = $scratch . '/prefix-router.php';
    writePrefixRouter($router, $signedPublic);
    $port = random_int(19000, 39000);
    [$proc] = startHttpServer($signedRoot, $signedPublic, $router, $port);
    try {
        check(true, 'isolated PHP HTTP server starts');
        $responses = [];
        foreach (['/2026/cms2/api/pages/home?locale=de', '/2026/cms2/api/pages/home?locale=en', '/2026/cms2/api/menus/main?locale=de', '/2026/cms2/api/menus/main?locale=en', '/2026/cms2/api/collections/ui-string/published?locale=de', '/2026/cms2/api/collections/ui-string/published?locale=en'] as $path) {
            $ctx=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>3,'header'=>"Accept: application/json\r\n"]]);
            $body=@file_get_contents("http://127.0.0.1:{$port}{$path}",false,$ctx);
            $status=0; foreach ($http_response_header ?? [] as $h) if (preg_match('/^HTTP\/\S+ (\d+)/',$h,$m)) $status=(int)$m[1];
            if ($status !== 200) $diagnostics[] = ['step' => 'http', 'path' => $path, 'status' => $status, 'body' => $body];
            $decoded = is_string($body) ? json_decode($body, true) : null;
            check($status===200 && is_array($decoded), "REST route responds with JSON: {$path}");
            $responses[$path] = $decoded;
        }
        foreach (['/2026/cms2/api/pages/home?locale=de','/2026/cms2/api/pages/home?locale=en'] as $path) {
            $data = $responses[$path]['data']['data'] ?? [];
            check(($data['title'] ?? '') === 'TurboScience' && count($data['sections'] ?? []) > 0, "localized TurboScience page payload loads: {$path}");
        }
        $pageDe = $responses['/2026/cms2/api/pages/home?locale=de']['data'] ?? [];
        $mediaEntries = $responses['/2026/cms2/api/pages/home?locale=de']['media'] ?? [];
        $mediaUrl = null;
        foreach ($mediaEntries as $entry) if (is_array($entry) && isset($entry['urls']['original'])) { $mediaUrl = $entry['urls']['original']; break; }
        check(is_string($mediaUrl) && str_starts_with($mediaUrl, '/2026/cms2/api/media/file/'), 'resolved media URLs retain the configured public prefix');
        if (is_string($mediaUrl)) {
            $mediaBody = @file_get_contents("http://127.0.0.1:{$port}{$mediaUrl}");
            check(is_string($mediaBody) && strlen($mediaBody) > 0, 'prefixed media URL is served by the isolated CMS');
        }

        $adminPath = '/2026/cms2/admin/pages/edit/home';
        $adminCtx=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>3]]);
        $adminHtml = @file_get_contents("http://127.0.0.1:{$port}{$adminPath}", false, $adminCtx);
        if (!is_string($adminHtml) || !str_contains($adminHtml, '<html')) $diagnostics[] = ['step' => 'admin', 'headers' => $http_response_header ?? [], 'body' => $adminHtml];
        check(is_string($adminHtml) && str_contains($adminHtml, '<html'), 'admin deep link serves the admin shell after candidate update');
        preg_match_all('/(?:src|href|component-url|renderer-url)=["\']([^"\']+\.(?:js|css))(?:\?[^"\']*)?["\']/', (string) $adminHtml, $assets);
        $assetPaths = array_values(array_unique($assets[1] ?? []));
        check(count($assetPaths) >= 2, 'admin shell references bundled JavaScript and CSS');
        foreach ($assetPaths as $assetPath) {
            $assetPathname = str_starts_with($assetPath, '/')
                ? $assetPath
                : '/2026/cms2/admin/' . ltrim(preg_replace('#^(?:\./)+#', '', $assetPath) ?? $assetPath, '/');
            $assetBody = @file_get_contents("http://127.0.0.1:{$port}{$assetPathname}");
            check(is_string($assetBody) && strlen($assetBody) > 0, "admin asset is served: {$assetPath}");
        }
    } finally {
        stopHttpServer($proc);
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
} finally {
    file_put_contents('/private/tmp/turboscience-upgrade-rehearsal-report.json', json_encode([
        'status' => $failures === [] ? 'passed' : 'failed', 'checks' => $checks, 'failures' => $failures,
        'method' => 'isolated installer, signed CLI and normal HTTP-triggered updates with shell disabled; interrupted-process recovery; no live feed or site requests',
        'scratch' => $scratch, 'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fwrite(STDOUT, "\nReport: /private/tmp/turboscience-upgrade-rehearsal-report.json\nScratch: {$scratch}\n");
}
exit($failures === [] ? 0 : 1);
