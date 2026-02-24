<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_social extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/social';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'social';
    }

    public function getPluginName(): string
    {
        return 'Social Sharing';
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
            'GET /api/social/providers' => [$this, 'listProviders'],
            'GET /api/social/settings' => [$this, 'getSettings'],
            'PUT /api/social/settings' => [$this, 'updateSettings'],
            'POST /api/social/share' => [$this, 'shareContent'],
            'GET /api/social/analytics' => [$this, 'getAnalytics'],
        ];
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'enabled_providers' => ['facebook', 'twitter', 'linkedin', 'email'],
                'position' => 'bottom',
                'style' => 'icon',
                'show_count' => false,
                'twitter_handle' => '',
                'og_image' => '',
                'bitly_token' => '',
                'providers' => [
                    'facebook' => [
                        'enabled' => true,
                        'app_id' => '',
                        'app_secret' => '',
                    ],
                    'twitter' => [
                        'enabled' => true,
                        'api_key' => '',
                        'api_secret' => '',
                    ],
                    'linkedin' => [
                        'enabled' => true,
                        'api_key' => '',
                        'api_secret' => '',
                    ],
                    'pinterest' => [
                        'enabled' => false,
                        'api_key' => '',
                    ],
                ],
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function loadAnalytics(): array
    {
        $file = $this->dataDir . '/analytics.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveAnalytics(array $analytics): void
    {
        $file = $this->dataDir . '/analytics.json';
        file_put_contents($file, json_encode($analytics, JSON_PRETTY_PRINT));
    }

    public function listProviders(): array
    {
        return [
            [
                'id' => 'facebook',
                'name' => 'Facebook',
                'color' => '#1877F2',
                'icon' => 'facebook',
                'enabled' => true,
            ],
            [
                'id' => 'twitter',
                'name' => 'X (Twitter)',
                'color' => '#000000',
                'icon' => 'twitter',
                'enabled' => true,
            ],
            [
                'id' => 'linkedin',
                'name' => 'LinkedIn',
                'color' => '#0A66C2',
                'icon' => 'linkedin',
                'enabled' => true,
            ],
            [
                'id' => 'pinterest',
                'name' => 'Pinterest',
                'color' => '#BD081C',
                'icon' => 'pinterest',
                'enabled' => false,
            ],
            [
                'id' => 'reddit',
                'name' => 'Reddit',
                'color' => '#FF4500',
                'icon' => 'reddit',
                'enabled' => false,
            ],
            [
                'id' => 'whatsapp',
                'name' => 'WhatsApp',
                'color' => '#25D366',
                'icon' => 'whatsapp',
                'enabled' => false,
            ],
            [
                'id' => 'email',
                'name' => 'Email',
                'color' => '#666666',
                'icon' => 'envelope',
                'enabled' => true,
            ],
        ];
    }

    public function getSettings(): array
    {
        $settings = $this->loadSettings();
        
        foreach ($settings['providers'] as &$provider) {
            if (!empty($provider['app_secret'])) {
                $provider['app_secret'] = '********';
            }
            if (!empty($provider['api_secret'])) {
                $provider['api_secret'] = '********';
            }
        }
        
        return $settings;
    }

    public function updateSettings(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $settings = $this->loadSettings();
        
        if (!empty($data['providers'])) {
            foreach ($data['providers'] as $key => $provider) {
                if (isset($provider['app_secret']) && $provider['app_secret'] !== '********') {
                    $settings['providers'][$key]['app_secret'] = $provider['app_secret'];
                }
                if (isset($provider['api_secret']) && $provider['api_secret'] !== '********') {
                    $settings['providers'][$key]['api_secret'] = $provider['api_secret'];
                }
                if (isset($provider['enabled'])) {
                    $settings['providers'][$key]['enabled'] = $provider['enabled'];
                }
                if (isset($provider['app_id'])) {
                    $settings['providers'][$key]['app_id'] = $provider['app_id'];
                }
                if (isset($provider['api_key'])) {
                    $settings['providers'][$key]['api_key'] = $provider['api_key'];
                }
            }
        }
        
        unset($data['providers']);
        $settings = array_merge($settings, $data);
        
        $this->saveSettings($settings);
        
        return $this->getSettings();
    }

    public function shareContent(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['url'])) {
            http_response_code(400);
            return ['error' => 'Missing URL'];
        }

        $id = bin2hex(random_bytes(8));
        
        $share = [
            'id' => $id,
            'url' => $data['url'],
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'provider' => $data['provider'] ?? 'unknown',
            'short_url' => $data['url'],
            'shared_at' => date('c'),
        ];

        $analytics = $this->loadAnalytics();
        $analytics[] = $share;
        
        if (count($analytics) > 10000) {
            $analytics = array_slice($analytics, -10000);
        }
        
        $this->saveAnalytics($analytics);

        return $share;
    }

    public function getAnalytics(): array
    {
        $analytics = $this->loadAnalytics();
        
        $byProvider = [];
        $byDay = [];
        
        foreach ($analytics as $share) {
            $provider = $share['provider'];
            $byProvider[$provider] = ($byProvider[$provider] ?? 0) + 1;
            
            $day = date('Y-m-d', strtotime($share['shared_at']));
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        return [
            'total_shares' => count($analytics),
            'by_provider' => $byProvider,
            'by_day' => $byDay,
            'recent_shares' => array_slice(array_reverse($analytics), 0, 20),
        ];
    }
}
