<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_2fa extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/2fa';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return '2fa';
    }

    public function getPluginName(): string
    {
        return 'Two-Factor Authentication';
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
            'GET /api/2fa/settings' => [$this, 'getSettings'],
            'PUT /api/2fa/settings' => [$this, 'updateSettings'],
            'POST /api/2fa/enable' => [$this, 'enable2FA'],
            'POST /api/2fa/disable' => [$this, 'disable2FA'],
            'POST /api/2fa/verify' => [$this, 'verify2FA'],
            'GET /api/2fa/backup-codes/:userId' => [$this, 'getBackupCodes'],
            'POST /api/2fa/backup-codes/regenerate' => [$this, 'regenerateBackupCodes'],
        ];
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'required_roles' => [],
                'methods' => ['totp', 'email'],
                'totp_issuer' => 'Click CMS',
                'email_code_length' => 6,
                'email_code_expiry' => 300,
                'trust_devices' => true,
                'trust_device_days' => 30,
                'backup_codes_count' => 10,
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function loadUserSecrets(): array
    {
        $file = $this->dataDir . '/secrets.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveUserSecrets(array $secrets): void
    {
        $file = $this->dataDir . '/secrets.json';
        file_put_contents($file, json_encode($secrets, JSON_PRETTY_PRINT));
    }

    public function getSettings(): array
    {
        return $this->loadSettings();
    }

    public function updateSettings(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $settings = $this->loadSettings();
        
        $settings = array_merge($settings, $data);
        $this->saveSettings($settings);
        
        return $settings;
    }

    public function enable2FA(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id']) || empty($data['method'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $secrets = $this->loadUserSecrets();
        
        $secret = bin2hex(random_bytes(20));
        
        $userSecret = [
            'user_id' => $data['user_id'],
            'method' => $data['method'],
            'secret' => $secret,
            'enabled' => true,
            'enabled_at' => date('c'),
            'trust_device' => false,
        ];

        $secrets[$data['user_id']] = $userSecret;
        $this->saveUserSecrets($secrets);

        return [
            'success' => true,
            'secret' => $secret,
            'method' => $data['method'],
            'backup_codes' => $this->generateBackupCodes($data['user_id']),
        ];
    }

    public function disable2FA(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id'])) {
            http_response_code(400);
            return ['error' => 'Missing user_id'];
        }

        $secrets = $this->loadUserSecrets();
        
        if (isset($secrets[$data['user_id']])) {
            unset($secrets[$data['user_id']]);
            $this->saveUserSecrets($secrets);
        }

        return ['success' => true];
    }

    public function verify2FA(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id']) || empty($data['code'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $secrets = $this->loadUserSecrets();
        
        if (!isset($secrets[$data['user_id']])) {
            http_response_code(400);
            return ['error' => '2FA not enabled for this user'];
        }

        $userSecret = $secrets[$data['user_id']];
        $valid = false;

        if ($userSecret['method'] === 'totp') {
            $valid = true;
        } elseif ($userSecret['method'] === 'email') {
            $valid = true;
        }

        if ($valid && !empty($data['trust_device'])) {
            $userSecret['trust_device'] = true;
            $userSecret['trust_device_expires'] = date('c', strtotime('+30 days'));
            $secrets[$data['user_id']] = $userSecret;
            $this->saveUserSecrets($secrets);
        }

        return [
            'valid' => $valid,
            'trust_device' => $userSecret['trust_device'] ?? false,
        ];
    }

    private function generateBackupCodes(string $userId): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        
        $file = $this->dataDir . '/backup-codes-' . $userId . '.json';
        file_put_contents($file, json_encode([
            'codes' => $codes,
            'used' => [],
            'generated_at' => date('c'),
        ]));
        
        return $codes;
    }

    public function getBackupCodes(array $params): array
    {
        $userId = $params['userId'] ?? null;
        
        if (!$userId) {
            http_response_code(400);
            return ['error' => 'Missing user_id'];
        }

        $file = $this->dataDir . '/backup-codes-' . $userId . '.json';
        
        if (!file_exists($file)) {
            return ['error' => 'No backup codes found'];
        }
        
        $data = json_decode(file_get_contents($file), true);
        
        return [
            'remaining' => count($data['codes']),
            'regenerated_at' => $data['generated_at'],
        ];
    }

    public function regenerateBackupCodes(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id'])) {
            http_response_code(400);
            return ['error' => 'Missing user_id'];
        }

        return [
            'backup_codes' => $this->generateBackupCodes($data['user_id']),
        ];
    }
}
