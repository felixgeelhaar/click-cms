<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_email extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/email';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'email';
    }

    public function getPluginName(): string
    {
        return 'Email / SMTP';
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
            'GET /api/email/settings' => [$this, 'getSettings'],
            'PUT /api/email/settings' => [$this, 'updateSettings'],
            'POST /api/email/test' => [$this, 'testSettings'],
            'GET /api/email/templates' => [$this, 'listTemplates'],
            'POST /api/email/templates' => [$this, 'createTemplate'],
            'GET /api/email/templates/:id' => [$this, 'getTemplate'],
            'PUT /api/email/templates/:id' => [$this, 'updateTemplate'],
            'DELETE /api/email/templates/:id' => [$this, 'deleteTemplate'],
            'POST /api/email/send' => [$this, 'sendEmail'],
            'GET /api/email/log' => [$this, 'listEmailLog'],
        ];
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'driver' => 'smtp',
                'smtp' => [
                    'host' => '',
                    'port' => 587,
                    'encryption' => 'tls',
                    'username' => '',
                    'password' => '',
                ],
                'mailgun' => [
                    'domain' => '',
                    'secret' => '',
                ],
                'sendgrid' => [
                    'api_key' => '',
                ],
                'from' => [
                    'address' => 'noreply@example.com',
                    'name' => 'Click CMS',
                ],
                'default_template' => 'default',
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function loadTemplates(): array
    {
        $file = $this->dataDir . '/templates.json';
        
        if (!file_exists($file)) {
            return [
                [
                    'id' => 'default',
                    'name' => 'Default',
                    'subject' => '{{subject}}',
                    'html' => '<html><body><h1>{{title}}</h1><p>{{content}}</p></body></html>',
                    'text' => "{{title}}\n\n{{content}}",
                    'variables' => ['title', 'content', 'footer'],
                ],
            ];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveTemplates(array $templates): void
    {
        $file = $this->dataDir . '/templates.json';
        file_put_contents($file, json_encode($templates, JSON_PRETTY_PRINT));
    }

    private function loadEmailLog(): array
    {
        $file = $this->dataDir . '/log.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveEmailLog(array $log): void
    {
        $file = $this->dataDir . '/log.json';
        file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT));
    }

    public function getSettings(): array
    {
        $settings = $this->loadSettings();
        
        if (!empty($settings['smtp']['password'])) {
            $settings['smtp']['password'] = '********';
        }
        
        return $settings;
    }

    public function updateSettings(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $settings = $this->loadSettings();
        
        if ($data['smtp']['password'] !== '********') {
            $settings['smtp']['password'] = $data['smtp']['password'];
        }
        
        unset($data['smtp']['password']);
        $settings = array_merge($settings, $data);
        
        $this->saveSettings($settings);
        
        return $this->getSettings();
    }

    public function testSettings(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['to'])) {
            http_response_code(400);
            return ['error' => 'Missing recipient email'];
        }

        return [
            'success' => true,
            'message' => 'Email test simulated successfully',
            'sent_to' => $data['to'],
            'sent_at' => date('c'),
        ];
    }

    public function listTemplates(): array
    {
        return $this->loadTemplates();
    }

    public function createTemplate(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['name']) || empty($data['subject'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $id = bin2hex(random_bytes(8));
        
        $template = [
            'id' => $id,
            'name' => $data['name'],
            'subject' => $data['subject'],
            'html' => $data['html'] ?? '',
            'text' => $data['text'] ?? '',
            'variables' => $data['variables'] ?? [],
            'created_at' => date('c'),
        ];

        $templates = $this->loadTemplates();
        $templates[] = $template;
        $this->saveTemplates($templates);

        return $template;
    }

    public function getTemplate(array $params): array
    {
        $id = $params['id'] ?? null;
        $templates = $this->loadTemplates();
        
        foreach ($templates as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }
        
        return ['error' => 'Template not found'];
    }

    public function updateTemplate(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $params['id'] ?? null;
        
        $templates = $this->loadTemplates();
        
        foreach ($templates as &$template) {
            if ($template['id'] === $id) {
                if (isset($data['name'])) {
                    $template['name'] = $data['name'];
                }
                if (isset($data['subject'])) {
                    $template['subject'] = $data['subject'];
                }
                if (isset($data['html'])) {
                    $template['html'] = $data['html'];
                }
                if (isset($data['text'])) {
                    $template['text'] = $data['text'];
                }
                if (isset($data['variables'])) {
                    $template['variables'] = $data['variables'];
                }
                
                $this->saveTemplates($templates);
                return $template;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Template not found'];
    }

    public function deleteTemplate(array $params): array
    {
        $id = $params['id'] ?? null;
        $templates = $this->loadTemplates();
        
        $filtered = array_filter($templates, function ($t) use ($id) {
            return $t['id'] !== $id;
        });
        
        $this->saveTemplates(array_values($filtered));
        
        return ['success' => true];
    }

    public function sendEmail(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['to', 'subject'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                return ['error' => "Missing required field: $field"];
            }
        }

        $settings = $this->loadSettings();
        $templates = $this->loadTemplates();
        
        $templateId = $data['template'] ?? $settings['default_template'];
        $template = null;
        
        foreach ($templates as $t) {
            if ($t['id'] === $templateId) {
                $template = $t;
                break;
            }
        }

        $id = bin2hex(random_bytes(8));
        
        $email = [
            'id' => $id,
            'to' => $data['to'],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'from' => $settings['from'],
            'subject' => $data['subject'],
            'body' => $data['body'] ?? '',
            'template' => $templateId,
            'variables' => $data['variables'] ?? [],
            'status' => 'sent',
            'sent_at' => date('c'),
        ];

        $log = $this->loadEmailLog();
        $log[] = $email;
        
        if (count($log) > 1000) {
            $log = array_slice($log, -1000);
        }
        
        $this->saveEmailLog($log);

        return $email;
    }

    public function listEmailLog(): array
    {
        $limit = intval($_GET['limit'] ?? 50);
        $status = $_GET['status'] ?? null;
        
        $log = $this->loadEmailLog();
        
        if ($status) {
            $log = array_filter($log, fn($e) => $e['status'] === $status);
        }
        
        return array_slice(array_reverse($log), 0, $limit);
    }
}
