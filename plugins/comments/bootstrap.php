<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_comments extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $dataDir = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->dataDir = $basePath . '/data/comments';
        
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'comments';
    }

    public function getPluginName(): string
    {
        return 'Comments System';
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
            'GET /api/comments' => [$this, 'listComments'],
            'POST /api/comments' => [$this, 'createComment'],
            'GET /api/comments/:id' => [$this, 'getComment'],
            'PUT /api/comments/:id' => [$this, 'updateComment'],
            'DELETE /api/comments/:id' => [$this, 'deleteComment'],
            'GET /api/comments/page/:pageId' => [$this, 'getPageComments'],
            'POST /api/comments/:id/reply' => [$this, 'replyToComment'],
            'POST /api/comments/:id/approve' => [$this, 'approveComment'],
            'POST /api/comments/:id/spam' => [$this, 'markAsSpam'],
            'GET /api/comments/settings' => [$this, 'getSettings'],
            'PUT /api/comments/settings' => [$this, 'updateSettings'],
        ];
    }

    private function loadComments(): array
    {
        $file = $this->dataDir . '/comments.json';
        
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function saveComments(array $comments): void
    {
        $file = $this->dataDir . '/comments.json';
        file_put_contents($file, json_encode($comments, JSON_PRETTY_PRINT));
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        
        if (!file_exists($file)) {
            return [
                'moderation' => 'manual',
                'require_approval' => true,
                'allow_guest' => false,
                'max_depth' => 3,
                'notify_email' => '',
                'notify_admin' => true,
                'allowed_html' => ['p', 'br', 'strong', 'em', 'a'],
                'blocked_words' => [],
                'akismet_key' => '',
            ];
        }
        
        return json_decode(file_get_contents($file), true);
    }

    private function saveSettings(array $settings): void
    {
        $file = $this->dataDir . '/settings.json';
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT));
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

    public function listComments(): array
    {
        $status = $_GET['status'] ?? null;
        $comments = $this->loadComments();
        
        if ($status) {
            $comments = array_filter($comments, fn($c) => $c['status'] === $status);
        }
        
        return array_values($comments);
    }

    public function getComment(array $params): array
    {
        $id = $params['id'] ?? null;
        $comments = $this->loadComments();
        
        foreach ($comments as $comment) {
            if ($comment['id'] === $id) {
                return $comment;
            }
        }
        
        return ['error' => 'Comment not found'];
    }

    public function getPageComments(array $params): array
    {
        $pageId = $params['pageId'] ?? null;
        $status = $_GET['status'] ?? 'approved';
        
        $comments = $this->loadComments();
        
        return array_values(array_filter($comments, function ($c) use ($pageId, $status) {
            if ($c['page_id'] !== $pageId) {
                return false;
            }
            if ($status && $c['status'] !== $status) {
                return false;
            }
            return true;
        }));
    }

    public function createComment(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $settings = $this->loadSettings();
        
        if (empty($data['page_id']) || empty($data['content'])) {
            http_response_code(400);
            return ['error' => 'Missing required fields'];
        }

        $id = bin2hex(random_bytes(8));
        
        $comment = [
            'id' => $id,
            'page_id' => $data['page_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'author_name' => $data['author_name'] ?? 'Anonymous',
            'author_email' => $data['author_email'] ?? '',
            'author_url' => $data['author_url'] ?? '',
            'content' => $data['content'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'status' => $settings['moderation'] === 'auto' ? 'approved' : 'pending',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $comments = $this->loadComments();
        $comments[] = $comment;
        $this->saveComments($comments);

        return $comment;
    }

    public function updateComment(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $params['id'] ?? null;
        
        $comments = $this->loadComments();
        
        foreach ($comments as &$comment) {
            if ($comment['id'] === $id) {
                if (isset($data['content'])) {
                    $comment['content'] = $data['content'];
                }
                if (isset($data['author_name'])) {
                    $comment['author_name'] = $data['author_name'];
                }
                $comment['updated_at'] = date('c');
                
                $this->saveComments($comments);
                return $comment;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Comment not found'];
    }

    public function deleteComment(array $params): array
    {
        $id = $params['id'] ?? null;
        $comments = $this->loadComments();
        
        $filtered = array_filter($comments, function ($c) use ($id) {
            return $c['id'] !== $id;
        });
        
        $this->saveComments(array_values($filtered));
        
        return ['success' => true];
    }

    public function replyToComment(array $params): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $parentId = $params['id'] ?? null;
        
        if (empty($data['content'])) {
            http_response_code(400);
            return ['error' => 'Missing content'];
        }

        $comments = $this->loadComments();
        $parent = null;
        
        foreach ($comments as $c) {
            if ($c['id'] === $parentId) {
                $parent = $c;
                break;
            }
        }
        
        if (!$parent) {
            http_response_code(404);
            return ['error' => 'Parent comment not found'];
        }

        $settings = $this->loadSettings();
        $id = bin2hex(random_bytes(8));
        
        $reply = [
            'id' => $id,
            'page_id' => $parent['page_id'],
            'parent_id' => $parentId,
            'author_name' => $data['author_name'] ?? 'Anonymous',
            'author_email' => $data['author_email'] ?? '',
            'content' => $data['content'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status' => 'approved',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $comments[] = $reply;
        $this->saveComments($comments);

        return $reply;
    }

    public function approveComment(array $params): array
    {
        $id = $params['id'] ?? null;
        $comments = $this->loadComments();
        
        foreach ($comments as &$comment) {
            if ($comment['id'] === $id) {
                $comment['status'] = 'approved';
                $comment['updated_at'] = date('c');
                
                $this->saveComments($comments);
                return $comment;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Comment not found'];
    }

    public function markAsSpam(array $params): array
    {
        $id = $params['id'] ?? null;
        $comments = $this->loadComments();
        
        foreach ($comments as &$comment) {
            if ($comment['id'] === $id) {
                $comment['status'] = 'spam';
                $comment['updated_at'] = date('c');
                
                $this->saveComments($comments);
                return $comment;
            }
        }
        
        http_response_code(404);
        return ['error' => 'Comment not found'];
    }
}
