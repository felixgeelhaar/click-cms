<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_redirects extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $redirectsFile = '';
    private string $logsFile = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->redirectsFile = $basePath . '/data/redirects.json';
        $this->logsFile = $basePath . '/data/redirects-logs.json';
    }

    public function getPluginId(): string
    {
        return 'redirects';
    }

    public function getPluginName(): string
    {
        return 'Redirects Manager';
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
        $this->handleRedirect();
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/redirects' => [$this, 'getRedirects'],
            'POST /api/redirects' => [$this, 'createRedirect'],
            'PUT /api/redirects/:id' => [$this, 'updateRedirect'],
            'DELETE /api/redirects/:id' => [$this, 'deleteRedirect'],
            'POST /api/redirects/import' => [$this, 'importRedirects'],
            'GET /api/redirects/export' => [$this, 'exportRedirects'],
            'GET /api/redirects/logs' => [$this, 'getRedirectLogs'],
        ];
    }

    private function handleRedirect(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        
        $redirects = $this->loadRedirects();
        
        foreach ($redirects as $redirect) {
            if (!$this->matchRedirect($path, $redirect)) {
                continue;
            }
            
            if ($this->detectRedirectLoop($path, $redirect['target'])) {
                error_log('Redirect loop detected: ' . $path . ' -> ' . $redirect['target']);
                return;
            }
            
            $this->logRedirect($path, $redirect);
            
            $statusCode = $redirect['type'] ?? 301;
            http_response_code($statusCode);
            header('Location: ' . $redirect['target']);
            exit;
        }
    }

    private function matchRedirect(string $path, array $redirect): bool
    {
        $source = $redirect['source'];
        
        if (isset($redirect['wildcard']) && $redirect['wildcard']) {
            $pattern = str_replace(['*', '?'], ['.*', '.'], $source);
            return preg_match('#^' . $pattern . '$#', $path) === 1;
        }
        
        return $path === $source || $path === '/' . ltrim($source, '/');
    }

    private function detectRedirectLoop(string $path, string $target): bool
    {
        $redirects = $this->loadRedirects();
        
        $visited = [];
        $current = $target;
        
        for ($i = 0; $i < 10; $i++) {
            if (in_array($current, $visited)) {
                return true;
            }
            
            $visited[] = $current;
            
            $found = false;
            foreach ($redirects as $redirect) {
                if ($this->matchRedirect($current, $redirect)) {
                    $current = $redirect['target'];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                return false;
            }
        }
        
        return true;
    }

    private function logRedirect(string $path, array $redirect): void
    {
        $logs = [];
        
        if (file_exists($this->logsFile)) {
            $logs = json_decode(file_get_contents($this->logsFile), true) ?? [];
        }
        
        $logs[] = [
            'timestamp' => date('c'),
            'source' => $path,
            'target' => $redirect['target'],
            'type' => $redirect['type'] ?? 301,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
        
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        file_put_contents($this->logsFile, json_encode($logs, JSON_PRETTY_PRINT));
    }

    private function loadRedirects(): array
    {
        if (!file_exists($this->redirectsFile)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($this->redirectsFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveRedirects(array $redirects): void
    {
        $dir = dirname($this->redirectsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->redirectsFile, json_encode($redirects, JSON_PRETTY_PRINT));
    }

    public function getRedirects(): array
    {
        return ['data' => $this->loadRedirects()];
    }

    public function createRedirect(): array
    {
        $data = $this->getJsonBody();
        
        $source = $data['source'] ?? '';
        $target = $data['target'] ?? '';
        
        if (empty($source) || empty($target)) {
            return ['error' => 'Source and target are required', 'status' => 400];
        }
        
        $redirects = $this->loadRedirects();
        
        $id = bin2hex(random_bytes(8));
        
        $redirects[] = [
            'id' => $id,
            'source' => '/' . ltrim($source, '/'),
            'target' => $target,
            'type' => $data['type'] ?? 301,
            'wildcard' => $data['wildcard'] ?? false,
            'enabled' => $data['enabled'] ?? true,
            'created_at' => date('c'),
        ];
        
        $this->saveRedirects($redirects);
        
        return ['data' => ['created' => true, 'id' => $id]];
    }

    public function updateRedirect(string $id): array
    {
        $data = $this->getJsonBody();
        $redirects = $this->loadRedirects();
        
        $found = false;
        foreach ($redirects as &$redirect) {
            if ($redirect['id'] === $id) {
                if (isset($data['source'])) {
                    $redirect['source'] = '/' . ltrim($data['source'], '/');
                }
                if (isset($data['target'])) {
                    $redirect['target'] = $data['target'];
                }
                if (isset($data['type'])) {
                    $redirect['type'] = $data['type'];
                }
                if (isset($data['wildcard'])) {
                    $redirect['wildcard'] = $data['wildcard'];
                }
                if (isset($data['enabled'])) {
                    $redirect['enabled'] = $data['enabled'];
                }
                
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['error' => 'Redirect not found', 'status' => 404];
        }
        
        $this->saveRedirects($redirects);
        
        return ['data' => ['updated' => true, 'id' => $id]];
    }

    public function deleteRedirect(string $id): array
    {
        $redirects = $this->loadRedirects();
        
        $newRedirects = array_filter($redirects, fn($r) => $r['id'] !== $id);
        
        if (count($newRedirects) === count($redirects)) {
            return ['error' => 'Redirect not found', 'status' => 404];
        }
        
        $this->saveRedirects(array_values($newRedirects));
        
        return ['data' => ['deleted' => true, 'id' => $id]];
    }

    public function importRedirects(): array
    {
        $data = $this->getJsonBody();
        $importData = $data['redirects'] ?? [];
        
        if (empty($importData)) {
            return ['error' => 'No redirects to import', 'status' => 400];
        }
        
        $redirects = $this->loadRedirects();
        
        $count = 0;
        foreach ($importData as $item) {
            if (empty($item['source']) || empty($item['target'])) {
                continue;
            }
            
            $id = bin2hex(random_bytes(8));
            
            $redirects[] = [
                'id' => $id,
                'source' => '/' . ltrim($item['source'], '/'),
                'target' => $item['target'],
                'type' => $item['type'] ?? 301,
                'wildcard' => $item['wildcard'] ?? false,
                'enabled' => $item['enabled'] ?? true,
                'created_at' => date('c'),
            ];
            
            $count++;
        }
        
        $this->saveRedirects($redirects);
        
        return ['data' => ['imported' => $count]];
    }

    public function exportRedirects(): array
    {
        return ['data' => $this->loadRedirects()];
    }

    public function getRedirectLogs(): array
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        
        $logs = [];
        
        if (file_exists($this->logsFile)) {
            $logs = json_decode(file_get_contents($this->logsFile), true) ?? [];
        }
        
        $logs = array_slice(array_reverse($logs), 0, $limit);
        
        return ['data' => $logs];
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
