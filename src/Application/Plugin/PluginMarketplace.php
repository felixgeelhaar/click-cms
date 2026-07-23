<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

class PluginMarketplace
{
    // A plugin archive is small. These caps turn a zip bomb — a tiny archive
    // that inflates to gigabytes — into a refused install rather than a full
    // disk, and are far above any real plugin.
    private const MAX_ENTRIES = 2000;
    private const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    private PluginManager $pluginManager;
    private string $pluginsPath;
    private string $marketplacePath;

    public function __construct(PluginManager $pluginManager, string $basePath)
    {
        $this->pluginManager = $pluginManager;
        $this->pluginsPath = $basePath . '/plugins';
        $this->marketplacePath = $basePath . '/data/marketplace';

        if (!is_dir($this->marketplacePath)) {
            mkdir($this->marketplacePath, 0755, true);
        }
    }

    public function uploadPlugin(array $file): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }

        $uploadPath = $this->marketplacePath . '/' . basename($file['name']);
        
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            return ['success' => false, 'error' => 'Failed to move uploaded file'];
        }

        return $this->installFromZip($uploadPath);
    }

    /**
     * Install a plugin from a ZIP.
     *
     * Installing a plugin is running its code, so the archive is treated as
     * hostile until proven otherwise. Every entry is validated before a byte is
     * written — an archive with an absolute path, a `..` component or a symlink
     * is refused outright rather than allowed to write a PHP shell into the
     * document root (the classic "Zip Slip"). Extraction goes to a temp dir on
     * the same filesystem as `plugins/`, so the final move is an atomic rename
     * rather than a cross-device copy.
     *
     * @param ?string $expectedId When set (a registry install), the plugin's own
     *        id must match the signed id it was fetched under; a package that
     *        installs under a different name than the one verified is rejected.
     */
    public function installFromZip(string $zipPath, ?string $expectedId = null): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file'];
        }

        // A temp dir beside the plugins directory, so the final install is a
        // same-filesystem rename and cannot half-copy.
        $tempDir = $this->marketplacePath . '/.extract-' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            $zip->close();
            return ['success' => false, 'error' => 'Could not create a working directory'];
        }

        $extractError = $this->safeExtract($zip, $tempDir);
        $zip->close();

        if ($extractError !== null) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => $extractError];
        }

        $pluginJsonPath = $this->findPluginJson($tempDir);
        if (!$pluginJsonPath) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'No plugin.json found in ZIP'];
        }

        $metadata = json_decode((string) file_get_contents($pluginJsonPath), true);
        if (!is_array($metadata) || !isset($metadata['name']) || !is_string($metadata['name'])) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Invalid plugin.json'];
        }

        $pluginId = $this->generatePluginId($metadata['name']);
        if (!$this->isPluginIdSafe($pluginId)) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Plugin name does not yield a usable id'];
        }

        // A registry install verified a signed id; the package must install under
        // that same id, or the signature guaranteed nothing about what runs.
        if ($expectedId !== null && $pluginId !== $expectedId) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Package id does not match the signed plugin id'];
        }

        $targetDir = $this->pluginsPath . '/' . $pluginId;
        if (is_dir($targetDir)) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Plugin already exists'];
        }

        if (!@rename(dirname($pluginJsonPath), $targetDir)) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Failed to install the plugin files'];
        }
        $this->cleanup($tempDir);

        $this->pluginManager->discover();

        return [
            'success' => true,
            'plugin' => [
                'id' => $pluginId,
                'name' => $metadata['name'],
                'version' => $metadata['version'] ?? '1.0.0',
            ],
        ];
    }

    /**
     * Extract an archive one validated entry at a time. Returns an error message
     * when the archive is unsafe or oversized, or null on success. Nothing is
     * written for an entry that does not pass, so a single bad entry aborts the
     * whole install with nothing left behind but the temp dir the caller cleans.
     */
    private function safeExtract(\ZipArchive $zip, string $dest): ?string
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return 'Archive has too many entries';
        }

        $root = rtrim($dest, '/') . '/';
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return 'Unreadable archive entry';
            }

            $name = $stat['name'];

            // Reject anything that could escape the destination: an absolute
            // path, a Windows drive or UNC path, a backslash (which some tools
            // write and some resolve), or any `..` component.
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                || str_contains($name, "\0") || preg_match('#^[A-Za-z]:#', $name) === 1) {
                return 'Archive entry has an unsafe path';
            }
            $parts = explode('/', $name);
            if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
                return 'Archive entry has an unsafe path';
            }

            // A directory entry ends in '/', which the extraction below creates
            // implicitly. Everything else is a file whose inflated size counts
            // against the bomb cap.
            if (str_ends_with($name, '/')) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_TOTAL_BYTES) {
                return 'Archive is too large when extracted';
            }

            $targetPath = $root . $name;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                return 'Could not create an extraction directory';
            }

            // Read the entry's bytes and write them ourselves rather than calling
            // extractTo, so a symlink stored in the archive becomes a plain file
            // of its target path — never a live symlink that a later write could
            // follow out of the tree.
            $stream = $zip->getStream($name);
            if ($stream === false) {
                return 'Unreadable archive entry';
            }
            $bytes = stream_get_contents($stream);
            fclose($stream);
            if ($bytes === false || file_put_contents($targetPath, $bytes) === false) {
                return 'Failed to write an extracted file';
            }
            // Never executable, and never inheriting odd modes from the archive.
            @chmod($targetPath, 0644);
        }

        return null;
    }

    private function isPluginIdSafe(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) === 1;
    }

    public function getAvailablePlugins(): array
    {
        $plugins = [];
        
        if (!is_dir($this->marketplacePath)) {
            return $plugins;
        }

        foreach (glob($this->marketplacePath . '/*.zip') as $zipFile) {
            $plugins[] = [
                'name' => basename($zipFile, '.zip'),
                'file' => basename($zipFile),
                'size' => filesize($zipFile),
            ];
        }

        return $plugins;
    }

    public function getRegistryCatalog(string $registryUrl, string $publicKey): array
    {
        if ($registryUrl === '' || $publicKey === '') {
            return [
                'available' => [],
                'errors' => ['Registry URL or public key not configured'],
            ];
        }

        $registry = $this->fetchJson($registryUrl);
        if (!is_array($registry)) {
            return [
                'available' => [],
                'errors' => ['Failed to fetch registry'],
            ];
        }

        $entries = $registry['plugins'] ?? $registry;
        if (!is_array($entries)) {
            return [
                'available' => [],
                'errors' => ['Invalid registry format'],
            ];
        }

        $available = [];
        $errors = [];

        foreach ($entries as $entry) {
            $manifestUrl = $entry['manifestUrl'] ?? null;
            $signature = $entry['signature'] ?? null;

            if ($manifestUrl === null || $signature === null) {
                $errors[] = 'Registry entry missing manifestUrl or signature';
                continue;
            }

            $manifestRaw = $this->fetchRaw($manifestUrl);
            if ($manifestRaw === null) {
                $errors[] = 'Failed to fetch manifest';
                continue;
            }

            if (!$this->verifySignature($manifestRaw, $signature, $publicKey)) {
                $errors[] = 'Invalid manifest signature';
                continue;
            }

            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                $errors[] = 'Invalid manifest JSON';
                continue;
            }

            $available[] = $manifest;
        }

        return [
            'available' => $available,
            'errors' => $errors,
        ];
    }

    public function installFromRegistry(string $registryUrl, string $publicKey, string $pluginId, ?string $version = null): array
    {
        $catalog = $this->getRegistryCatalog($registryUrl, $publicKey);

        if (!empty($catalog['errors'])) {
            return ['success' => false, 'error' => $catalog['errors'][0]];
        }

        $plugin = null;
        foreach ($catalog['available'] as $entry) {
            if (($entry['id'] ?? '') !== $pluginId) {
                continue;
            }
            if ($version !== null && ($entry['version'] ?? '') !== $version) {
                continue;
            }
            $plugin = $entry;
            break;
        }

        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found in registry'];
        }

        $packageUrl = $plugin['packageUrl'] ?? null;
        $expectedHash = $plugin['sha256'] ?? null;

        if ($packageUrl === null || $expectedHash === null) {
            return ['success' => false, 'error' => 'Manifest missing packageUrl or sha256'];
        }

        $tempFile = $this->marketplacePath . '/' . $pluginId . '-' . ($plugin['version'] ?? 'latest') . '.zip';
        $downloaded = $this->fetchToFile($packageUrl, $tempFile);

        if (!$downloaded) {
            return ['success' => false, 'error' => 'Failed to download package'];
        }

        $actualHash = hash_file('sha256', $tempFile);
        if (!hash_equals($expectedHash, $actualHash)) {
            unlink($tempFile);
            return ['success' => false, 'error' => 'Package checksum mismatch'];
        }

        // Install under the id the signed manifest declared, and require the
        // package to agree — the signature only means anything about the plugin
        // whose id it covers.
        $result = $this->installFromZip($tempFile, $pluginId);
        unlink($tempFile);

        return $result;
    }

    private function findPluginJson(string $dir): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === 'plugin.json') {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function generatePluginId(string $name): string
    {
        $id = strtolower(preg_replace('/[^a-z0-9]/i', '-', $name));
        $id = preg_replace('/-+/', '-', $id);
        return trim($id, '-');
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($dir);
        }
    }

    private function fetchJson(string $url): ?array
    {
        $raw = $this->fetchRaw($url);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    private function fetchRaw(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'follow_location' => 1,
                'user_agent' => 'ClickCMS/0.1.0',
            ]
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            return null;
        }

        return $data;
    }

    private function fetchToFile(string $url, string $dest): bool
    {
        $data = $this->fetchRaw($url);
        if ($data === null) {
            return false;
        }

        return file_put_contents($dest, $data) !== false;
    }

    private function verifySignature(string $payload, string $signature, string $publicKey): bool
    {
        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }

        $result = openssl_verify($payload, $decoded, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }
}
