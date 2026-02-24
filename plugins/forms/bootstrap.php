<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_forms extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $formsDir = '';
    private string $submissionsDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->formsDir = $basePath . '/data/forms';
        $this->submissionsDir = $basePath . '/data/forms/submissions';
        
        foreach ([$this->formsDir, $this->submissionsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function getPluginId(): string
    {
        return 'forms';
    }

    public function getPluginName(): string
    {
        return 'Forms';
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
            'GET /api/forms' => [$this, 'getForms'],
            'POST /api/forms' => [$this, 'createForm'],
            'GET /api/forms/:id' => [$this, 'getForm'],
            'PUT /api/forms/:id' => [$this, 'updateForm'],
            'DELETE /api/forms/:id' => [$this, 'deleteForm'],
            'GET /api/forms/:id/submissions' => [$this, 'getSubmissions'],
            'POST /api/forms/:id/submit' => [$this, 'submitForm'],
            'POST /api/forms/:id/test' => [$this, 'testEmail'],
        ];
    }

    public function hook_web_render(array $params): ?string
    {
        return $this->renderFormShortcode($params);
    }

    private function renderFormShortcode(array $params): ?string
    {
        $page = $params['page'] ?? null;
        if (!$page) {
            return null;
        }
        
        $content = $page->content() ?? '';
        
        if (!preg_match_all('/\[form\s+id=["\']?([^"\'\s\]]+)["\']?\]/', $content, $matches)) {
            return null;
        }
        
        $replacements = [];
        
        foreach ($matches[1] as $formId) {
            $form = $this->loadForm($formId);
            if (!$form) {
                $replacements[$matches[0][$key ?? 0]] = '<p>Form not found</p>';
                continue;
            }
            
            $replacements[$matches[0][$key ?? 0]] = $this->renderFormHtml($form);
        }
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function renderFormHtml(array $form): string
    {
        $html = '<form method="POST" action="/api/forms/' . $form['id'] . '/submit" class="click-form">';
        $html .= '<input type="hidden" name="form_id" value="' . $form['id'] . '">';
        
        foreach ($form['fields'] as $field) {
            $html .= $this->renderField($field);
        }
        
        $html .= '<button type="submit">' . ($form['submit_label'] ?? 'Submit') . '</button>';
        $html .= '</form>';
        
        return $html;
    }

    private function renderField(array $field): string
    {
        $name = $field['name'] ?? '';
        $label = $field['label'] ?? '';
        $required = $field['required'] ?? false;
        $type = $field['type'] ?? 'text';
        
        $html = '<div class="form-field form-field-' . $type . '">';
        $html .= '<label for="' . $name . '">' . $label . ($required ? ' *' : '') . '</label>';
        
        switch ($type) {
            case 'textarea':
                $html .= '<textarea name="' . $name . '" id="' . $name . '"';
                $html .= ($field['placeholder'] ?? '') ? ' placeholder="' . $field['placeholder'] . '"' : '';
                $html .= $required ? ' required' : '';
                $html .= '></textarea>';
                break;
                
            case 'select':
                $html .= '<select name="' . $name . '" id="' . $name . '"';
                $html .= $required ? ' required' : '';
                $html .= '>';
                foreach ($field['options'] ?? [] as $option) {
                    $html .= '<option value="' . $option['value'] . '">' . $option['label'] . '</option>';
                }
                $html .= '</select>';
                break;
                
            case 'checkbox':
                $html .= '<input type="checkbox" name="' . $name . '" id="' . $name . '"';
                $html .= $required ? ' required' : '';
                $html .= '>';
                $html .= '<label for="' . $name . '">' . $label . '</label>';
                break;
                
            case 'radio':
                foreach ($field['options'] ?? [] as $option) {
                    $html .= '<input type="radio" name="' . $name . '" value="' . $option['value'] . '"';
                    $html .= $required ? ' required' : '';
                    $html .= '>';
                    $html .= '<label>' . $option['label'] . '</label>';
                }
                break;
                
            case 'email':
            case 'tel':
            case 'url':
            case 'number':
            case 'date':
                $html .= '<input type="' . $type . '" name="' . $name . '" id="' . $name . '"';
                $html .= ($field['placeholder'] ?? '') ? ' placeholder="' . $field['placeholder'] . '"' : '';
                $html .= ($field['min'] ?? '') ? ' min="' . $field['min'] . '"' : '';
                $html .= ($field['max'] ?? '') ? ' max="' . $field['max'] . '"' : '';
                $html .= $required ? ' required' : '';
                $html .= '>';
                break;
                
            default:
                $html .= '<input type="text" name="' . $name . '" id="' . $name . '"';
                $html .= ($field['placeholder'] ?? '') ? ' placeholder="' . $field['placeholder'] . '"' : '';
                $html .= $required ? ' required' : '';
                $html .= '>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function loadForm(string $id): ?array
    {
        $file = $this->formsDir . '/' . $id . '.json';
        if (!file_exists($file)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function saveForm(array $form): void
    {
        $file = $this->formsDir . '/' . $form['id'] . '.json';
        file_put_contents($file, json_encode($form, JSON_PRETTY_PRINT));
    }

    public function getForms(): array
    {
        $forms = [];
        
        $files = glob($this->formsDir . '/*.json');
        if ($files) {
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    unset($data['notifications']);
                    $forms[] = $data;
                }
            }
        }
        
        return ['data' => $forms];
    }

    public function getForm(string $id): array
    {
        $form = $this->loadForm($id);
        
        if (!$form) {
            return ['error' => 'Form not found', 'status' => 404];
        }
        
        return ['data' => $form];
    }

    public function createForm(): array
    {
        $data = $this->getJsonBody();
        
        $name = $data['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'Form name is required', 'status' => 400];
        }
        
        $id = bin2hex(random_bytes(8));
        
        $form = [
            'id' => $id,
            'name' => $name,
            'description' => $data['description'] ?? '',
            'fields' => $data['fields'] ?? [],
            'submit_label' => $data['submit_label'] ?? 'Submit',
            'notifications' => $data['notifications'] ?? [],
            'spam_protection' => $data['spam_protection'] ?? ['honeypot' => true],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        
        $this->saveForm($form);
        
        return ['data' => $form, 'status' => 201];
    }

    public function updateForm(string $id): array
    {
        $form = $this->loadForm($id);
        
        if (!$form) {
            return ['error' => 'Form not found', 'status' => 404];
        }
        
        $data = $this->getJsonBody();
        
        if (isset($data['name'])) {
            $form['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $form['description'] = $data['description'];
        }
        if (isset($data['fields'])) {
            $form['fields'] = $data['fields'];
        }
        if (isset($data['submit_label'])) {
            $form['submit_label'] = $data['submit_label'];
        }
        if (isset($data['notifications'])) {
            $form['notifications'] = $data['notifications'];
        }
        if (isset($data['spam_protection'])) {
            $form['spam_protection'] = $data['spam_protection'];
        }
        
        $form['updated_at'] = date('c');
        
        $this->saveForm($form);
        
        return ['data' => $form];
    }

    public function deleteForm(string $id): array
    {
        $file = $this->formsDir . '/' . $id . '.json';
        
        if (!file_exists($file)) {
            return ['error' => 'Form not found', 'status' => 404];
        }
        
        unlink($file);
        
        return ['data' => ['deleted' => true, 'id' => $id]];
    }

    public function getSubmissions(string $id): array
    {
        $form = $this->loadForm($id);
        
        if (!$form) {
            return ['error' => 'Form not found', 'status' => 404];
        }
        
        $submissions = [];
        
        $files = glob($this->submissionsDir . '/' . $id . '-*.json');
        if ($files) {
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $submissions[] = $data;
                }
            }
        }
        
        usort($submissions, function($a, $b) {
            return strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? '');
        });
        
        return ['data' => $submissions];
    }

    public function submitForm(string $id): array
    {
        $form = $this->loadForm($id);
        
        if (!$form) {
            return ['error' => 'Form not found', 'status' => 404];
        }
        
        $data = $this->getJsonBody();
        
        if (isset($form['spam_protection']['honeypot']) && $form['spam_protection']['honeypot']) {
            if (!empty($data['website'])) {
                return ['data' => ['success' => true, 'message' => 'Thank you for your submission!']];
            }
        }
        
        $validation = $this->validateForm($form, $data);
        if ($validation !== true) {
            return ['error' => $validation, 'status' => 400];
        }
        
        $submission = [
            'id' => bin2hex(random_bytes(8)),
            'form_id' => $id,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'submitted_at' => date('c'),
        ];
        
        $submissionFile = $this->submissionsDir . '/' . $id . '-' . $submission['id'] . '.json';
        file_put_contents($submissionFile, json_encode($submission, JSON_PRETTY_PRINT));
        
        $this->sendNotifications($form, $submission);
        
        return ['data' => ['success' => true, 'message' => 'Thank you for your submission!']];
    }

    private function validateForm(array $form, array $data): true|string
    {
        foreach ($form['fields'] ?? [] as $field) {
            $name = $field['name'] ?? '';
            $required = $field['required'] ?? false;
            $type = $field['type'] ?? 'text';
            
            if ($required && empty($data[$name] ?? '')) {
                return 'Field ' . ($field['label'] ?? $name) . ' is required';
            }
            
            if (!empty($data[$name]) && $type === 'email') {
                if (!filter_var($data[$name], FILTER_VALIDATE_EMAIL)) {
                    return 'Invalid email address';
                }
            }
        }
        
        return true;
    }

    private function sendNotifications(array $form, array $submission): void
    {
        $notifications = $form['notifications'] ?? [];
        
        foreach ($notifications as $notification) {
            if ($notification['type'] === 'email' && !empty($notification['to'])) {
                $this->sendEmail(
                    $notification['to'],
                    $notification['subject'] ?? 'New form submission',
                    $submission['data']
                );
            }
        }
    }

    private function sendEmail(string $to, string $subject, array $data): void
    {
        $body = "New form submission:\n\n";
        foreach ($data as $key => $value) {
            if ($key !== 'form_id' && $key !== 'website') {
                $body .= ucfirst($key) . ": " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }
        
        $headers = "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        mail($to, $subject, $body, $headers);
    }

    public function testEmail(): array
    {
        $data = $this->getJsonBody();
        
        $to = $data['email'] ?? '';
        
        if (empty($to)) {
            return ['error' => 'Email address required', 'status' => 400];
        }
        
        $this->sendEmail($to, 'Test Email from Click CMS Forms', [
            'message' => 'This is a test email from the Forms plugin.'
        ]);
        
        return ['data' => ['sent' => true]];
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
