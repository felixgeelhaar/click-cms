<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_webhooks extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $webhooksDir = '';
    private string $logsDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->webhooksDir = $basePath . '/data/webhooks';
        $this->logsDir = $basePath . '/data/webhooks/logs';
        
        foreach ([$this->webhooksDir, $this->logsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function getPluginId(): string
    {
        return 'webhooks';
    }

    public function getPluginName(): string
    {
        return 'Webhooks';
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

    public function hook_page_save(array $params): void
    {
        $this->triggerWebhooks('page.save', [
            'slug' => $params['slug'] ?? '',
            'title' => $params['title'] ?? '',
        ]);
    }

    public function hook_page_delete(array $params): void
    {
        $this->triggerWebhooks('page.delete', [
            'slug' => $params['slug'] ?? '',
        ]);
    }

    public function hook_user_login(array $params): void
    {
        $this->triggerWebhooks('user.login', [
            'username' => $params['username'] ?? '',
        ]);
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/webhooks' => [$this, 'getWebhooks'],
            'POST /api/webhooks' => [$this, 'createWebhook'],
            'GET /api/webhooks/:id' => [$this, 'getWebhook'],
            'PUT /api/webhooks/:id' => [$this, 'updateWebhook'],
            'DELETE /api/webhooks/:id' => [$this, 'deleteWebhook'],
            'POST /api/webhooks/:id/test' => [$this, 'testWebhook'],
            'GET /api/webhooks/logs' => [$this, 'getWebhookLogs'],
        ];
    }

    private function loadWebhooks(): array
    {
        $file = $this->webhooksDir . '/webhooks.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveWebhooks(array $webhooks): void
    {
        $file = $this->webhooksDir . '/webhooks.json';
        file_put_contents($file, json_encode($webhooks, JSON_PRETTY_PRINT));
    }

    private function triggerWebhooks(string $event, array $data): void
    {
        $webhooks = $this->loadWebhooks();
        
        foreach ($webhooks as $webhook) {
            if (!($webhook['enabled'] ?? true)) {
                continue;
            }
            
            $events = $webhook['events'] ?? [];
            
            if (!in_array($event, $events) && !in_array('*', $events)) {
                continue;
            }
            
            $this->sendWebhook($webhook, $event, $data);
        }
    }

    private function sendWebhook(array $webhook, string $event, array $data): void
    {
        $url = $webhook['url'] ?? '';
        
        if (empty($url)) {
            return;
        }
        
        $payload = [
            'event' => $event,
            'timestamp' => date('c'),
            'data' => $data,
        ];
        
        $payloadTemplate = $webhook['payload_template'] ?? null;
        if ($payloadTemplate) {
            $payload = $this->applyTemplate($payloadTemplate, $payload);
        }
        
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Click-CMS-Webhooks/1.0',
        ];
        
        if (!empty($webhook['secret'])) {
            $signature = hash_hmac('sha256', json_encode($payload), $webhook['secret']);
            $headers[] = 'X-Webhook-Signature: ' . $signature;
        }
        
        $this->logWebhook($webhook, $event, $payload, 'pending');
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        
        $startTime = microtime(true);
        
        $response = @file_get_contents($url, false, $context);
        
        $duration = microtime(true) - $startTime;
        
        $success = $response !== false;
        
        $this->logWebhook($webhook, $event, $payload, $success ? 'success' : 'failed', $response, $duration);
    }

    private function applyTemplate(string $template, array $payload): array
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT);
        
        foreach ($payload as $key => $value) {
            $template = str_replace('{{' . $key . '}}', is_array($value) ? json_encode($value) : $value, $template);
        }
        
        return json_decode($template, true) ?? $payload;
    }

    private function logWebhook(array $webhook, string $event, array $payload, string $status, ?string $response = null, ?float $duration = null): void
    {
        $logFile = $this->logsDir . '/' . $webhook['id'] . '.json';
        
        $logs = [];
        if (file_exists($logFile)) {
            $logs = json_decode(file_get_contents($logFile), true) ?? [];
        }
        
        $logs[] = [
            'id' => bin2hex(random_bytes(8)),
            'timestamp' => date('c'),
            'event' => $event,
            'payload' => $payload,
            'status' => $status,
            'response' => $response,
            'duration' => $duration,
        ];
        
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
    }

    public function getWebhooks(): array
    {
        return ['data' => $this->loadWebhooks()];
    }

    public function getWebhook(string $id): array
    {
        $webhooks = $this->loadWebhooks();
        
        foreach ($webhooks as $webhook) {
            if ($webhook['id'] === $id) {
                return ['data' => $webhook];
            }
        }
        
        return ['error' => 'Webhook not found', 'status' => 404];
    }

    public function createWebhook(): array
    {
        $data = $this->getJsonBody();
        
        $url = $data['url'] ?? '';
        if (empty($url)) {
            return ['error' => 'Webhook URL is required', 'status' => 400];
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'Invalid URL', 'status' => 400];
        }
        
        $webhooks = $this->loadWebhooks();
        
        $webhook = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $data['name'] ?? 'Untitled Webhook',
            'url' => $url,
            'events' => $data['events'] ?? ['*'],
            'secret' => $data['secret'] ?? bin2hex(random_bytes(32)),
            'payload_template' => $data['payload_template'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'retry_count' => $data['retry_count'] ?? 3,
            'created_at' => date('c'),
        ];
        
        $webhooks[] = $webhook;
        $this->saveWebhooks($webhooks);
        
        return ['data' => $webhook, 'status' => 201];
    }

    public function updateWebhook(string $id): array
    {
        $data = $this->getJsonBody();
        $webhooks = $this->loadWebhooks();
        
        $found = false;
        foreach ($webhooks as &$webhook) {
            if ($webhook['id'] === $id) {
                if (isset($data['name'])) {
                    $webhook['name'] = $data['name'];
                }
                if (isset($data['url'])) {
                    $webhook['url'] = $data['url'];
                }
                if (isset($data['events'])) {
                    $webhook['events'] = $data['events'];
                }
                if (isset($data['secret'])) {
                    $webhook['secret'] = $data['secret'];
                }
                if (isset($data['payload_template'])) {
                    $webhook['payload_template'] = $data['payload_template'];
                }
                if (isset($data['enabled'])) {
                    $webhook['enabled'] = $data['enabled'];
                }
                if (isset($data['retry_count'])) {
                    $webhook['retry_count'] = $data['retry_count'];
                }
                
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['error' => 'Webhook not found', 'status' => 404];
        }
        
        $this->saveWebhooks($webhooks);
        
        return ['data' => ['updated' => true, 'id' => $id]];
    }

    public function deleteWebhook(string $id): array
    {
        $webhooks = $this->loadWebhooks();
        
        $newWebhooks = array_filter($webhooks, fn($w) => $w['id'] !== $id);
        
        if (count($newWebhooks) === count($webhooks)) {
            return ['error' => 'Webhook not found', 'status' => 404];
        }
        
        $this->saveWebhooks(array_values($newWebhooks));
        
        $logFile = $this->logsDir . '/' . $id . '.json';
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        
        return ['data' => ['deleted' => true, 'id' => $id]];
    }

    public function testWebhook(string $id): array
    {
        $webhooks = $this->loadWebhooks();
        
        $webhook = null;
        foreach ($webhooks as $w) {
            if ($w['id'] === $id) {
                $webhook = $w;
                break;
            }
        }
        
        if (!$webhook) {
            return ['error' => 'Webhook not found', 'status' => 404];
        }
        
        $this->sendWebhook($webhook, 'test', [
            'message' => 'This is a test webhook from Click CMS',
        ]);
        
        return ['data' => ['sent' => true]];
    }

    public function getWebhookLogs(): array
    {
        $webhookId = $_GET['webhook_id'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        
        if ($webhookId) {
            $logFile = $this->logsDir . '/' . $webhookId . '.json';
            
            if (!file_exists($logFile)) {
                return ['data' => []];
            }
            
            $logs = json_decode(file_get_contents($logFile), true) ?? [];
            $logs = array_slice(array_reverse($logs), 0, $limit);
            
            return ['data' => $logs];
        }
        
        $allLogs = [];
        
        $files = glob($this->logsDir . '/*.json');
        if ($files) {
            foreach ($files as $file) {
                $logs = json_decode(file_get_contents($file), true) ?? [];
                $allLogs = array_merge($allLogs, $logs);
            }
        }
        
        usort($allLogs, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        
        $allLogs = array_slice($allLogs, 0, $limit);
        
        return ['data' => $allLogs];
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
