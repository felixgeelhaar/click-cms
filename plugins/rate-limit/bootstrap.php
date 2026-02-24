<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_rate_limit extends \Click\Cms\Application\Plugin\BasePlugin
{
    private array $limits = [];
    private string $storageFile = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->storageFile = $basePath . '/data/rate-limit.json';
        
        $this->limits = [
            'auth' => [
                'requests' => 10,
                'window' => 60,
                'message' => 'Too many authentication attempts. Please try again later.',
            ],
            'write' => [
                'requests' => 30,
                'window' => 60,
                'message' => 'Rate limit exceeded for write operations.',
            ],
            'api' => [
                'requests' => 100,
                'window' => 60,
                'message' => 'API rate limit exceeded.',
            ],
        ];
    }

    public function getPluginId(): string
    {
        return 'rate-limit';
    }

    public function getPluginName(): string
    {
        return 'Rate Limiting';
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
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $ip = $this->getClientIp();
        
        if (!$this->isApiRequest($uri)) {
            return;
        }
        
        $limit = $this->getApplicableLimit($uri, $method);
        
        if (!$this->checkRateLimit($ip, $limit['key'], $limit['requests'], $limit['window'])) {
            $this->rateLimitExceeded($limit['message']);
        }
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/rate-limit/status' => [$this, 'getStatus'],
        ];
    }

    private function isApiRequest(string $uri): bool
    {
        return str_starts_with($uri, '/api/');
    }

    private function getApplicableLimit(string $uri, string $method): array
    {
        if (str_contains($uri, '/api/auth/')) {
            return [
                'key' => 'auth',
                'requests' => $this->limits['auth']['requests'],
                'window' => $this->limits['auth']['window'],
                'message' => $this->limits['auth']['message'],
            ];
        }
        
        if ($method !== 'GET') {
            return [
                'key' => 'write',
                'requests' => $this->limits['write']['requests'],
                'window' => $this->limits['write']['window'],
                'message' => $this->limits['write']['message'],
            ];
        }
        
        return [
            'key' => 'api',
            'requests' => $this->limits['api']['requests'],
            'window' => $this->limits['api']['window'],
            'message' => $this->limits['api']['message'],
        ];
    }

    private function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }
        
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        
        return $ip;
    }

    private function checkRateLimit(string $identifier, string $key, int $limit, int $window): bool
    {
        $data = $this->loadRateLimitData();
        
        $now = time();
        $windowStart = $now - $window;
        
        if (!isset($data[$key])) {
            $data[$key] = [];
        }
        
        if (!isset($data[$key][$identifier])) {
            $data[$key][$identifier] = [];
        }
        
        $requests = array_filter($data[$key][$identifier], function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        $count = count($requests);
        
        if ($count >= $limit) {
            return false;
        }
        
        $requests[] = $now;
        $data[$key][$identifier] = array_values($requests);
        
        $this->saveRateLimitData($data);
        
        return true;
    }

    private function loadRateLimitData(): array
    {
        if (!file_exists($this->storageFile)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($this->storageFile), true);
        
        return is_array($data) ? $data : [];
    }

    private function saveRateLimitData(array $data): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $now = time();
        $cleanup = $now - 3600;
        
        foreach ($data as $key => &$bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            
            foreach ($bucket as $identifier => &$timestamps) {
                if (!is_array($timestamps)) {
                    continue;
                }
                
                $timestamps = array_filter($timestamps, function($ts) use ($cleanup) {
                    return $ts > $cleanup;
                });
                
                if (empty($timestamps)) {
                    unset($bucket[$identifier]);
                }
            }
            
            if (empty($bucket)) {
                unset($data[$key]);
            }
        }
        
        file_put_contents($this->storageFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function rateLimitExceeded(string $message): void
    {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        header('X-RateLimit-Limit: ' . ($this->limits['auth']['requests'] ?? 10));
        echo json_encode([
            'error' => $message,
            'code' => 'RATE_LIMIT_EXCEEDED',
        ]);
        exit;
    }

    public function getStatus(): array
    {
        $data = $this->loadRateLimitData();
        
        $stats = [];
        
        foreach ($this->limits as $key => $limit) {
            $count = isset($data[$key]) ? count($data[$key]) : 0;
            $stats[$key] = [
                'requests' => $limit['requests'],
                'window_seconds' => $limit['window'],
                'active_identifiers' => $count,
            ];
        }
        
        return [
            'data' => [
                'enabled' => true,
                'limits' => $stats,
            ],
        ];
    }
}
