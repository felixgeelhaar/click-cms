<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_maintenance_mode extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $configFile = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->configFile = $basePath . '/data/maintenance.json';
    }

    public function getPluginId(): string
    {
        return 'maintenance-mode';
    }

    public function getPluginName(): string
    {
        return 'Maintenance Mode';
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

    public function hook_request_start(array $params): void
    {
        $config = $this->loadConfig();
        
        if (!$config['enabled'] ?? false) {
            return;
        }
        
        if ($this->isWhitelistedIp()) {
            return;
        }
        
        if ($this->isAdminUser()) {
            return;
        }
        
        $this->showMaintenancePage($config);
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/maintenance/status' => [$this, 'getStatus'],
            'POST /api/maintenance/enable' => [$this, 'enableMaintenance'],
            'POST /api/maintenance/disable' => [$this, 'disableMaintenance'],
            'GET /api/maintenance/config' => [$this, 'getMaintenanceConfig'],
            'PUT /api/maintenance/config' => [$this, 'updateConfig'],
        ];
    }

    private function loadConfig(): array
    {
        $defaults = [
            'enabled' => false,
            'message' => 'Site is under maintenance. We will be back soon.',
            'title' => 'Maintenance Mode',
            'allowed_ips' => [],
            'scheduled_start' => null,
            'scheduled_end' => null,
            'show_countdown' => false,
            'redirect_url' => null,
        ];
        
        if (!file_exists($this->configFile)) {
            return $defaults;
        }
        
        $data = json_decode(file_get_contents($this->configFile), true);
        return array_merge($defaults, $data ?? []);
    }

    private function saveConfig(array $config): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    private function isWhitelistedIp(): bool
    {
        $config = $this->loadConfig();
        $allowedIps = $config['allowed_ips'] ?? [];
        
        if (empty($allowedIps)) {
            return false;
        }
        
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        
        return in_array($clientIp, $allowedIps);
    }

    private function isAdminUser(): bool
    {
        $sessionFile = dirname($this->configFile) . '/../session.json';
        
        if (!file_exists($sessionFile)) {
            return false;
        }
        
        $session = json_decode(file_get_contents($sessionFile), true);
        
        return ($session['user']['role'] ?? '') === 'admin';
    }

    private function showMaintenancePage(array $config): void
    {
        $title = $config['title'] ?? 'Maintenance Mode';
        $message = $config['message'] ?? 'We will be back soon.';
        
        $countdown = '';
        if (($config['show_countdown'] ?? false) && !empty($config['scheduled_end'])) {
            $countdown = '<div id="maintenance-countdown" data-end="' . $config['scheduled_end'] . '"></div>';
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            padding: 20px;
        }
        .container {
            text-align: center;
            max-width: 600px;
        }
        h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #00d4ff, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        p {
            font-size: 1.25rem;
            color: #a0a0a0;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .countdown {
            font-size: 2rem;
            font-weight: bold;
            color: #00d4ff;
            margin: 2rem 0;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚧</div>
        <h1>{$title}</h1>
        <p>{$message}</p>
        {$countdown}
    </div>
</body>
</html>
HTML;

        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 3600');
        echo $html;
        exit;
    }

    public function getStatus(): array
    {
        $config = $this->loadConfig();
        
        return ['data' => [
            'enabled' => $config['enabled'] ?? false,
            'scheduled_start' => $config['scheduled_start'] ?? null,
            'scheduled_end' => $config['scheduled_end'] ?? null,
        ]];
    }

    public function getMaintenanceConfig(): array
    {
        return ['data' => $this->loadConfig()];
    }

    public function enableMaintenance(): array
    {
        $config = $this->loadConfig();
        $config['enabled'] = true;
        
        $this->saveConfig($config);
        
        return ['data' => ['enabled' => true]];
    }

    public function disableMaintenance(): array
    {
        $config = $this->loadConfig();
        $config['enabled'] = false;
        
        $this->saveConfig($config);
        
        return ['data' => ['enabled' => false]];
    }

    public function updateConfig(): array
    {
        $data = $this->getJsonBody();
        $config = $this->loadConfig();
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['enabled', 'message', 'title', 'allowed_ips', 'scheduled_start', 'scheduled_end', 'show_countdown', 'redirect_url'])) {
                $config[$key] = $value;
            }
        }
        
        $this->saveConfig($config);
        
        return ['data' => $config];
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
}
