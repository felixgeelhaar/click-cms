<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_audit_log extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
    }

    public function getPluginId(): string
    {
        return 'audit-log';
    }

    public function getPluginName(): string
    {
        return 'Audit Log';
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
            'GET /api/audit-log' => [$this, 'getAuditLog'],
            'GET /api/audit-log/events' => [$this, 'getAuditEvents'],
        ];
    }

    public function hook_plugin_activate(array $params): void
    {
        $this->logEvent('plugin_activate', [
            'plugin_id' => $params['plugin_id'] ?? '',
            'plugin_name' => $params['plugin_name'] ?? '',
        ]);
    }

    public function hook_plugin_deactivate(array $params): void
    {
        $this->logEvent('plugin_deactivate', [
            'plugin_id' => $params['plugin_id'] ?? '',
            'plugin_name' => $params['plugin_name'] ?? '',
        ]);
    }

    public function hook_plugin_install(array $params): void
    {
        $this->logEvent('plugin_install', [
            'plugin_id' => $params['plugin_id'] ?? '',
            'plugin_name' => $params['plugin_name'] ?? '',
            'version' => $params['version'] ?? '',
        ]);
    }

    public function hook_page_save(array $params): void
    {
        $this->logEvent('page_save', [
            'page_slug' => $params['slug'] ?? '',
            'page_title' => $params['title'] ?? '',
        ]);
    }

    public function hook_user_login(array $params): void
    {
        $this->logEvent('user_login', [
            'username' => $params['username'] ?? '',
        ]);
    }

    public function hook_user_logout(array $params): void
    {
        $this->logEvent('user_logout', [
            'username' => $params['username'] ?? '',
        ]);
    }

    public function hook_user_create(array $params): void
    {
        $this->logEvent('user_create', [
            'username' => $params['username'] ?? '',
            'email' => $params['email'] ?? '',
            'role' => $params['role'] ?? 'editor',
        ]);
    }

    public function hook_user_update(array $params): void
    {
        $this->logEvent('user_update', [
            'username' => $params['username'] ?? '',
            'changes' => $params['changes'] ?? [],
        ]);
    }

    public function hook_user_delete(array $params): void
    {
        $this->logEvent('user_delete', [
            'username' => $params['username'] ?? '',
            'deleted_by' => $params['deleted_by'] ?? '',
        ]);
    }

    public function hook_user_role_change(array $params): void
    {
        $this->logEvent('user_role_change', [
            'username' => $params['username'] ?? '',
            'old_role' => $params['old_role'] ?? '',
            'new_role' => $params['new_role'] ?? '',
        ]);
    }

    public function hook_user_status_change(array $params): void
    {
        $this->logEvent('user_status_change', [
            'username' => $params['username'] ?? '',
            'old_status' => $params['old_status'] ?? '',
            'new_status' => $params['new_status'] ?? '',
        ]);
    }

    public function getAuditLog(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $auditFile = $basePath . '/data/audit.json';
        
        if (!file_exists($auditFile)) {
            return ['data' => []];
        }
        
        $events = json_decode(file_get_contents($auditFile), true);
        
        if (!is_array($events)) {
            return ['data' => []];
        }
        
        $events = array_reverse($events);
        
        return ['data' => $events];
    }

    public function getAuditEvents(): array
    {
        $data = $this->getJsonBody();
        $type = $data['type'] ?? null;
        $limit = isset($data['limit']) ? (int)$data['limit'] : 100;
        $offset = isset($data['offset']) ? (int)$data['offset'] : 0;
        
        $basePath = $this->pluginManager->getBasePath();
        $auditFile = $basePath . '/data/audit.json';
        
        if (!file_exists($auditFile)) {
            return ['data' => [], 'total' => 0];
        }
        
        $events = json_decode(file_get_contents($auditFile), true);
        
        if (!is_array($events)) {
            return ['data' => [], 'total' => 0];
        }
        
        if ($type) {
            $events = array_filter($events, fn($e) => ($e['event'] ?? '') === $type);
        }
        
        $total = count($events);
        $events = array_slice(array_reverse($events), $offset, $limit);
        
        return [
            'data' => $events,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function logEvent(string $eventType, array $details = []): void
    {
        $basePath = $this->pluginManager->getBasePath();
        $auditFile = $basePath . '/data/audit.json';
        
        $events = [];
        
        if (file_exists($auditFile)) {
            $events = json_decode(file_get_contents($auditFile), true) ?? [];
        }
        
        if (!is_array($events)) {
            $events = [];
        }
        
        $username = 'system';
        
        $sessionFile = $basePath . '/data/session.json';
        if (file_exists($sessionFile)) {
            $session = json_decode(file_get_contents($sessionFile), true);
            if (isset($session['user']['username'])) {
                $username = $session['user']['username'];
            }
        }
        
        $events[] = [
            'id' => bin2hex(random_bytes(8)),
            'timestamp' => date('c'),
            'event' => $eventType,
            'user' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => $details,
        ];
        
        $maxEvents = 10000;
        if (count($events) > $maxEvents) {
            $events = array_slice($events, -$maxEvents);
        }
        
        file_put_contents($auditFile, json_encode($events, JSON_PRETTY_PRINT));
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
