<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_custom_fields extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $fieldsDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->fieldsDir = $basePath . '/data/custom-fields';
        
        if (!is_dir($this->fieldsDir)) {
            mkdir($this->fieldsDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'custom-fields';
    }

    public function getPluginName(): string
    {
        return 'Custom Fields';
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
            'GET /api/custom-fields/groups' => [$this, 'getFieldGroups'],
            'POST /api/custom-fields/groups' => [$this, 'createFieldGroup'],
            'GET /api/custom-fields/groups/:id' => [$this, 'getFieldGroup'],
            'PUT /api/custom-fields/groups/:id' => [$this, 'updateFieldGroup'],
            'DELETE /api/custom-fields/groups/:id' => [$this, 'deleteFieldGroup'],
        ];
    }

    private function loadFieldGroups(): array
    {
        $file = $this->fieldsDir . '/groups.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveFieldGroups(array $groups): void
    {
        $file = $this->fieldsDir . '/groups.json';
        file_put_contents($file, json_encode($groups, JSON_PRETTY_PRINT));
    }

    public function getFieldGroups(): array
    {
        return ['data' => $this->loadFieldGroups()];
    }

    public function createFieldGroup(): array
    {
        $data = $this->getJsonBody();
        
        $name = $data['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'Field group name is required', 'status' => 400];
        }
        
        $groups = $this->loadFieldGroups();
        
        $id = bin2hex(random_bytes(8));
        
        $group = [
            'id' => $id,
            'name' => $name,
            'description' => $data['description'] ?? '',
            'post_types' => $data['post_types'] ?? ['page'],
            'fields' => $data['fields'] ?? [],
            'position' => $data['position'] ?? 'normal',
            'created_at' => date('c'),
        ];
        
        $groups[] = $group;
        $this->saveFieldGroups($groups);
        
        return ['data' => $group, 'status' => 201];
    }

    public function getFieldGroup(string $id): array
    {
        $groups = $this->loadFieldGroups();
        
        foreach ($groups as $group) {
            if ($group['id'] === $id) {
                return ['data' => $group];
            }
        }
        
        return ['error' => 'Field group not found', 'status' => 404];
    }

    public function updateFieldGroup(string $id): array
    {
        $data = $this->getJsonBody();
        $groups = $this->loadFieldGroups();
        
        $found = false;
        foreach ($groups as &$group) {
            if ($group['id'] === $id) {
                if (isset($data['name'])) {
                    $group['name'] = $data['name'];
                }
                if (isset($data['description'])) {
                    $group['description'] = $data['description'];
                }
                if (isset($data['post_types'])) {
                    $group['post_types'] = $data['post_types'];
                }
                if (isset($data['fields'])) {
                    $group['fields'] = $data['fields'];
                }
                if (isset($data['position'])) {
                    $group['position'] = $data['position'];
                }
                
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['error' => 'Field group not found', 'status' => 404];
        }
        
        $this->saveFieldGroups($groups);
        
        return ['data' => ['updated' => true, 'id' => $id]];
    }

    public function deleteFieldGroup(string $id): array
    {
        $groups = $this->loadFieldGroups();
        
        $newGroups = array_filter($groups, fn($g) => $g['id'] !== $id);
        
        if (count($newGroups) === count($groups)) {
            return ['error' => 'Field group not found', 'status' => 404];
        }
        
        $this->saveFieldGroups(array_values($newGroups));
        
        return ['data' => ['deleted' => true, 'id' => $id]];
    }

    public function getFieldTypes(): array
    {
        return [
            'text' => [
                'name' => 'Text',
                'render' => 'text',
                'default' => '',
            ],
            'textarea' => [
                'name' => 'Text Area',
                'render' => 'textarea',
                'default' => '',
            ],
            'number' => [
                'name' => 'Number',
                'render' => 'number',
                'default' => 0,
            ],
            'email' => [
                'name' => 'Email',
                'render' => 'email',
                'default' => '',
            ],
            'url' => [
                'name' => 'URL',
                'render' => 'url',
                'default' => '',
            ],
            'checkbox' => [
                'name' => 'Checkbox',
                'render' => 'checkbox',
                'default' => false,
            ],
            'select' => [
                'name' => 'Select',
                'render' => 'select',
                'options' => [],
            ],
            'radio' => [
                'name' => 'Radio',
                'render' => 'radio',
                'options' => [],
            ],
            'date' => [
                'name' => 'Date',
                'render' => 'date',
                'default' => '',
            ],
            'datetime' => [
                'name' => 'DateTime',
                'render' => 'datetime',
                'default' => '',
            ],
            'color' => [
                'name' => 'Color',
                'render' => 'color',
                'default' => '#000000',
            ],
            'image' => [
                'name' => 'Image',
                'render' => 'image',
                'default' => '',
            ],
            'file' => [
                'name' => 'File',
                'render' => 'file',
                'default' => '',
            ],
            'repeater' => [
                'name' => 'Repeater',
                'render' => 'repeater',
                'fields' => [],
            ],
            'wysiwyg' => [
                'name' => 'Rich Text Editor',
                'render' => 'wysiwyg',
                'default' => '',
            ],
        ];
    }

    public function renderField(array $field, $value): string
    {
        $type = $field['type'] ?? 'text';
        $name = $field['name'] ?? '';
        $id = $field['id'] ?? $name;
        
        $html = '<div class="custom-field custom-field-' . $type . '">';
        $html .= '<label for="' . $id . '">' . $field['label'] ?? $name . '</label>';
        
        switch ($type) {
            case 'textarea':
                $html .= '<textarea name="' . $name . '" id="' . $id . '"';
                $html .= ($field['placeholder'] ?? '') ? ' placeholder="' . $field['placeholder'] . '"' : '';
                $html .= ($field['rows'] ?? '') ? ' rows="' . $field['rows'] . '"' : '';
                $html .= '>' . esc_html($value ?? $field['default'] ?? '') . '</textarea>';
                break;
                
            case 'select':
                $html .= '<select name="' . $name . '" id="' . $id . '">';
                foreach ($field['options'] ?? [] as $option) {
                    $selected = ($value ?? $field['default'] ?? '') === $option['value'] ? ' selected' : '';
                    $html .= '<option value="' . $option['value'] . '"' . $selected . '>' . $option['label'] . '</option>';
                }
                $html .= '</select>';
                break;
                
            case 'checkbox':
                $checked = ($value ?? $field['default'] ?? false) ? ' checked' : '';
                $html .= '<input type="checkbox" name="' . $name . '" id="' . $id . '"' . $checked . '>';
                break;
                
            case 'radio':
                foreach ($field['options'] ?? [] as $option) {
                    $checked = ($value ?? $field['default'] ?? '') === $option['value'] ? ' checked' : '';
                    $html .= '<input type="radio" name="' . $name . '" value="' . $option['value'] . '" id="' . $id . '_' . $option['value'] . '"' . $checked . '>';
                    $html .= '<label for="' . $id . '_' . $option['value'] . '">' . $option['label'] . '</label>';
                }
                break;
                
            case 'number':
                $html .= '<input type="number" name="' . $name . '" id="' . $id . '"';
                $html .= ($field['min'] ?? '') ? ' min="' . $field['min'] . '"' : '';
                $html .= ($field['max'] ?? '') ? ' max="' . $field['max'] . '"' : '';
                $html .= ' value="' . ($value ?? $field['default'] ?? 0) . '">';
                break;
                
            case 'wysiwyg':
                $html .= '<textarea name="' . $name . '" id="' . $id . '" class="wysiwyg"';
                $html .= ($field['rows'] ?? '') ? ' rows="' . $field['rows'] . '"' : '';
                $html .= '>' . esc_html($value ?? $field['default'] ?? '') . '</textarea>';
                break;
                
            default:
                $html .= '<input type="' . $type . '" name="' . $name . '" id="' . $id . '"';
                $html .= ($field['placeholder'] ?? '') ? ' placeholder="' . $field['placeholder'] . '"' : '';
                $html .= ' value="' . esc_attr($value ?? $field['default'] ?? '') . '">';
        }
        
        if ($field['description'] ?? '') {
            $html .= '<p class="field-description">' . $field['description'] . '</p>';
        }
        
        $html .= '</div>';
        
        return $html;
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

function esc_html(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
