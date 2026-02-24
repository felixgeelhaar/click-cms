<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_gdpr extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/gdpr';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'gdpr';
    }

    public function getPluginName(): string
    {
        return 'GDPR / CCPA Compliance';
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
            'GET /api/gdpr/settings' => [$this, 'getSettings'],
            'PUT /api/gdpr/settings' => [$this, 'updateSettings'],
            'GET /api/gdpr/consents' => [$this, 'listConsents'],
            'POST /api/gdpr/consents' => [$this, 'recordConsent'],
            'GET /api/gdpr/requests' => [$this, 'listDataRequests'],
            'POST /api/gdpr/requests' => [$this, 'createDataRequest'],
            'GET /api/gdpr/requests/:id' => [$this, 'getDataRequest'],
            'POST /api/gdpr/requests/:id/process' => [$this, 'processDataRequest'],
            'GET /api/gdpr/cookies' => [$this, 'listCookies'],
            'POST /api/gdpr/cookies' => [$this, 'addCookie'],
            'DELETE /api/gdpr/cookies/:id' => [$this, 'deleteCookie'],
        ];
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'regulations' => ['gdpr'],
                'cookie_banner' => [
                    'enabled' => true,
                    'position' => 'bottom',
                    'style' => 'dark',
                    'accept_all' => true,
                    'reject_all' => true,
                    'customize' => true,
                ],
                'privacy_policy' => [
                    'enabled' => true,
                    'page_id' => '',
                    'last_updated' => date('Y-m-d'),
                ],
                'data_retention' => [
                    'default_days' => 365,
                    'user_inactive_days' => 730,
                ],
                'consent_tracking' => true,
                'right_to_erasure' => true,
                'data_portability' => true,
                'breach_notification' => true,
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function loadConsents(): array
    {
        $file = $this->dataDir . '/consents.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveConsents(array $consents): void
    {
        $file = $this->dataDir . '/consents.json';
        file_put_contents($file, json_encode($consents, JSON_PRETTY_PRINT));
    }

    private function loadDataRequests(): array
    {
        $file = $this->dataDir . '/requests.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveDataRequests(array $requests): void
    {
        $file = $this->dataDir . '/requests.json';
        file_put_contents($file, json_encode($requests, JSON_PRETTY_PRINT));
    }

    private function loadCookies(): array
    {
        $file = $this->dataDir . '/cookies.json';
        
        if (!file_exists($file)) {
            return [
                [
                    'id' => 'session',
                    'name' => 'Session',
                    'domain' => 'self',
                    'duration' => 'session',
                    'purpose' => 'Session management',
                    'category' => 'essential',
                    'required' => true,
                ],
                [
                    'id' => 'csrf',
                    'name' => 'CSRF Token',
                    'domain' => 'self',
                    'duration' => 'session',
                    'purpose' => 'Security',
                    'category' => 'essential',
                    'required' => true,
                ],
            ];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveCookies(array $cookies): void
    {
        $file = $this->dataDir . '/cookies.json';
        file_put_contents($file, json_encode($cookies, JSON_PRETTY_PRINT));
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

    public function listConsents(): array
    {
        return $this->loadConsents();
    }

    public function recordConsent(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['type']) || empty($data['granted'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $id = bin2hex(random_bytes(8));
        
        $consent = [
            'id' => $id,
            'type' => $data['type'],
            'granted' => $data['granted'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'recorded_at' => date('c'),
        ];

        $consents = $this->loadConsents();
        $consents[] = $consent;
        $this->saveConsents($consents);

        return $consent;
    }

    public function listDataRequests(): array
    {
        $status = $_GET['status'] ?? null;
        $requests = $this->loadDataRequests();
        
        if ($status) {
            $requests = array_filter($requests, fn($r) => $r['status'] === $status);
        }
        
        return array_values($requests);
    }

    public function createDataRequest(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['email']) || empty($data['type'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $id = bin2hex(random_bytes(8));
        
        $request = [
            'id' => $id,
            'email' => $data['email'],
            'type' => $data['type'],
            'status' => 'pending',
            'request_date' => date('c'),
            'due_date' => date('c', strtotime('+30 days')),
            'completed_date' => null,
            'notes' => '',
        ];

        $requests = $this->loadDataRequests();
        $requests[] = $request;
        $this->saveDataRequests($requests);

        return $request;
    }

    public function getDataRequest(array $params): array
    {
        $id = $params['id'] ?? null;
        $requests = $this->loadDataRequests();
        
        foreach ($requests as $request) {
            if ($request['id'] === $id) {
                return $request;
            }
        }
        
        return ['error' => 'Request not found'];
    }

    public function processDataRequest(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $params['id'] ?? null;
        
        $requests = $this->loadDataRequests();
        
        foreach ($requests as &$request) {
            if ($request['id'] === $id) {
                $request['status'] = $data['status'] ?? 'completed';
                $request['completed_date'] = date('c');
                $request['notes'] = $data['notes'] ?? '';
                
                $this->saveDataRequests($requests);
                return $request;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Request not found'];
    }

    public function listCookies(): array
    {
        return $this->loadCookies();
    }

    public function addCookie(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['name']) || empty($data['purpose'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $id = bin2hex(random_bytes(8));
        
        $cookie = [
            'id' => $id,
            'name' => $data['name'],
            'domain' => $data['domain'] ?? 'self',
            'duration' => $data['duration'] ?? '1 year',
            'purpose' => $data['purpose'],
            'category' => $data['category'] ?? 'analytics',
            'required' => $data['required'] ?? false,
        ];

        $cookies = $this->loadCookies();
        $cookies[] = $cookie;
        $this->saveCookies($cookies);

        return $cookie;
    }

    public function deleteCookie(array $params): array
    {
        $id = $params['id'] ?? null;
        $cookies = $this->loadCookies();
        
        $filtered = array_filter($cookies, function ($c) use ($id) {
            return $c['id'] !== $id;
        });
        
        $this->saveCookies(array_values($filtered));
        
        return ['success' => true];
    }
}
