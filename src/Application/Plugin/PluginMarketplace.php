<?php

declare(strict_types=1);

namespace Click\Cms\Application\Plugin;

class PluginMarketplace
{
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

    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found'];
        }

        $zip = new \ZipArchive();
        
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file'];
        }

        // Extract to temp directory first
        $tempDir = sys_get_temp_dir() . '/click-cms-plugin-' . uniqid();
        mkdir($tempDir, 0755, true);
        
        $zip->extractTo($tempDir);
        $zip->close();

        // Find plugin.json
        $pluginJsonPath = $this->findPluginJson($tempDir);
        
        if (!$pluginJsonPath) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'No plugin.json found in ZIP'];
        }

        $metadata = json_decode(file_get_contents($pluginJsonPath), true);
        
        if (!$metadata || !isset($metadata['name'])) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Invalid plugin.json'];
        }

        $pluginId = $this->generatePluginId($metadata['name']);
        $targetDir = $this->pluginsPath . '/' . $pluginId;

        if (is_dir($targetDir)) {
            $this->cleanup($tempDir);
            return ['success' => false, 'error' => 'Plugin already exists'];
        }

        // Move plugin to plugins directory
        rename(dirname($pluginJsonPath), $targetDir);
        $this->cleanup($tempDir);

        // Rediscover plugins
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

        $result = $this->installFromZip($tempFile);
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
