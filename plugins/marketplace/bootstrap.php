<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';
require_once __DIR__ . '/../../src/Application/Plugin/PluginMarketplace.php';

class Plugin_marketplace extends \Click\Cms\Application\Plugin\BasePlugin
{
    private $marketplace;

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        $this->marketplace = new \Click\Cms\Application\Plugin\PluginMarketplace(
            $pluginManager,
            $pluginManager->getBasePath()
        );
    }

    public function getPluginId(): string
    {
        return 'marketplace';
    }

    public function getPluginName(): string
    {
        return 'Plugin Marketplace';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function deactivate(): bool
    {
        return true;
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/marketplace' => [$this, 'getMarketplace'],
            'POST /api/marketplace/install' => [$this, 'installFromMarketplace'],
            'POST /api/marketplace/upload' => [$this, 'uploadPlugin'],
        ];
    }

    public function getMarketplace(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/config/core.json';
        
        $registryUrl = '';
        $publicKey = '';
        
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $registryUrl = $config['core']['marketplace']['registryUrl'] ?? '';
            $publicKey = $config['core']['marketplace']['publicKey'] ?? '';
        }

        $catalog = $this->marketplace->getRegistryCatalog($registryUrl, $publicKey);
        
        $installed = [];
        foreach ($this->pluginManager->all() as $plugin) {
            $installed[] = [
                'id' => $plugin->id->value,
                'name' => $plugin->name,
                'version' => $plugin->version->value,
                'description' => $plugin->description,
                'author' => $plugin->author,
                'state' => $plugin->state->value,
            ];
        }

        return [
            'data' => [
                'available' => $catalog['available'],
                'installed' => $installed,
                'errors' => $catalog['errors'],
            ]
        ];
    }

    public function installFromMarketplace(): array
    {
        $user = $this->getSessionUser();
        
        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $role = $user['role'] ?? 'editor';
        if ($role !== 'admin') {
            return ['error' => 'Only admins can install plugins', 'status' => 403];
        }

        $data = $this->getJsonBody();
        $pluginId = $data['id'] ?? '';
        $version = $data['version'] ?? null;
        
        if (empty($pluginId)) {
            return ['error' => 'Plugin ID required', 'status' => 400];
        }

        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/config/core.json';
        
        $registryUrl = '';
        $publicKey = '';
        
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $registryUrl = $config['core']['marketplace']['registryUrl'] ?? '';
            $publicKey = $config['core']['marketplace']['publicKey'] ?? '';
        }

        $result = $this->marketplace->installFromRegistry($registryUrl, $publicKey, $pluginId, $version);
        
        if (!$result['success']) {
            return ['error' => $result['error'], 'status' => 400];
        }

        $depCheck = $this->checkPluginDependencies($pluginId, $result['plugin'] ?? null);
        if (!$depCheck['valid']) {
            $pluginDir = $basePath . '/plugins/' . $pluginId;
            if (is_dir($pluginDir)) {
                $this->recursiveDelete($pluginDir);
            }
            return ['error' => $depCheck['error'], 'status' => 400];
        }

        $this->logAuditEvent($user['username'], 'install', $pluginId, $result['plugin'] ?? null);

        return ['data' => $result];
    }

    private function recursiveDelete(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }

    private function checkPluginDependencies(string $pluginId, ?array $plugin): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $pluginPath = $basePath . '/plugins/' . $pluginId;
        
        $metadataFile = $pluginPath . '/plugin.json';
        if (!file_exists($metadataFile)) {
            return ['valid' => true];
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        $dependencies = $metadata['dependencies'] ?? [];
        
        if (empty($dependencies)) {
            return ['valid' => true];
        }
        
        $missing = [];
        $pluginManager = $this->pluginManager;
        
        foreach ($dependencies as $dep) {
            $depPlugin = null;
            try {
                $depPlugin = $pluginManager->get(\Click\Cms\Domain\ValueObjects\PluginId::fromString($dep));
            } catch (\Exception $e) {
                $missing[] = $dep;
                continue;
            }
            
            if ($depPlugin === null) {
                $missing[] = $dep;
            } elseif ($depPlugin->state !== \Click\Cms\Domain\Plugin\PluginState::ACTIVATED) {
                $missing[] = $dep . ' (not activated)';
            }
        }
        
        if (!empty($missing)) {
            return [
                'valid' => false,
                'error' => 'Missing dependencies: ' . implode(', ', $missing) . '. Please install and activate them first.',
            ];
        }
        
        return ['valid' => true];
    }

    public function uploadPlugin(): array
    {
        $user = $this->getSessionUser();
        
        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $role = $user['role'] ?? 'editor';
        if ($role !== 'admin') {
            return ['error' => 'Only admins can upload plugins', 'status' => 403];
        }

        if (!isset($_FILES['file'])) {
            return ['error' => 'No file uploaded', 'status' => 400];
        }

        $result = $this->marketplace->uploadPlugin($_FILES['file']);
        
        if (!$result['success']) {
            return ['error' => $result['error'], 'status' => 400];
        }

        $this->logAuditEvent($user['username'], 'upload', $result['plugin']['id'] ?? 'unknown', $result['plugin'] ?? null);

        return ['data' => $result];
    }

    private function getSessionUser(): ?array
    {
        $basePath = $this->pluginManager->getBasePath();
        $sessionFile = $basePath . '/data/session.json';
        
        if (!file_exists($sessionFile)) {
            return null;
        }

        $session = json_decode(file_get_contents($sessionFile), true);
        
        return $session['user'] ?? null;
    }

    private function getJsonBody(): array
    {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            return $_POST;
        }
        
        $data = json_decode($input, true);
        
        return $data ?? [];
    }

    private function logAuditEvent(string $username, string $action, string $pluginId, ?array $pluginData): void
    {
        $basePath = $this->pluginManager->getBasePath();
        $auditFile = $basePath . '/data/audit.json';
        
        $events = [];
        if (file_exists($auditFile)) {
            $events = json_decode(file_get_contents($auditFile), true) ?? [];
        }

        $events[] = [
            'timestamp' => date('c'),
            'username' => $username,
            'action' => $action,
            'plugin_id' => $pluginId,
            'plugin_data' => $pluginData,
        ];

        file_put_contents($auditFile, json_encode($events, JSON_PRETTY_PRINT));
    }
}
