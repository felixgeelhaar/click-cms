<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_rest_api extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function getPluginId(): string
    {
        return 'rest-api';
    }

    public function getPluginName(): string
    {
        return 'REST API';
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
            // Pages
            'GET /api/pages' => [$this, 'getPages'],
            'GET /api/pages/:slug' => [$this, 'getPage'],
            'GET /api/pages/:slug/versions' => [$this, 'getPageVersions'],
            'GET /api/pages/:slug/versions/:versionId' => [$this, 'getPageVersion'],
            'POST /api/pages/:slug/versions/:versionId/restore' => [$this, 'restorePageVersion'],
            'POST /api/pages' => [$this, 'createPage'],
            'PUT /api/pages/:slug' => [$this, 'updatePage'],
            'DELETE /api/pages/:slug' => [$this, 'deletePage'],
            
            // Users
            'GET /api/users' => [$this, 'getUsers'],
            'GET /api/users/:username' => [$this, 'getUser'],
            'POST /api/users' => [$this, 'createUser'],
            'PUT /api/users/:username' => [$this, 'updateUser'],
            'DELETE /api/users/:username' => [$this, 'deleteUser'],
            
            // Plugins
            'GET /api/plugins' => [$this, 'getPlugins'],
            'GET /api/plugins/:id' => [$this, 'getPlugin'],
            'POST /api/plugins/:id/activate' => [$this, 'activatePlugin'],
            'POST /api/plugins/:id/deactivate' => [$this, 'deactivatePlugin'],
            
            // Plugin Dependencies
            'GET /api/plugins/dependencies' => [$this, 'getPluginDependencies'],
            
            // System
            'GET /api/info' => [$this, 'getInfo'],
        ];
    }

    public function getInfo(): array
    {
        return [
            'data' => [
                'name' => 'Click CMS',
                'version' => '0.1.0',
                'endpoints' => [
                    'pages' => '/api/pages',
                    'users' => '/api/users',
                    'media' => '/api/media',
                    'plugins' => '/api/plugins',
                ],
            ],
        ];
    }

    public function getPlugins(): array
    {
        $plugins = $this->pluginManager->all();
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        
        $result = [];
        
        foreach ($plugins as $plugin) {
            $pluginData = [
                'id' => $plugin->id->value,
                'name' => $plugin->name,
                'description' => $plugin->description ?? '',
                'version' => $plugin->version->value,
                'author' => $plugin->author ?? '',
                'state' => $plugin->state->value,
                'dependencies' => $plugin->dependencies ?? [],
                'hooks' => $plugin->hooks ?? [],
            ];
            
            $result[] = $pluginData;
        }
        
        return ['data' => $result];
    }

    public function getPlugin(string $id): array
    {
        try {
            $pluginId = \Click\Cms\Domain\ValueObjects\PluginId::fromString($id);
        } catch (\Exception $e) {
            return ['error' => 'Invalid plugin ID', 'status' => 400];
        }
        
        $plugin = $this->pluginManager->get($pluginId);
        
        if ($plugin === null) {
            return ['error' => 'Plugin not found', 'status' => 404];
        }
        
        $basePath = $this->pluginManager->getBasePath();
        $pluginPath = $basePath . '/plugins/' . $plugin->id->value;
        
        $changelog = null;
        $changelogPath = $pluginPath . '/CHANGELOG.md';
        if (file_exists($changelogPath)) {
            $changelog = file_get_contents($changelogPath);
        }
        
        $signature = null;
        $signaturePath = $pluginPath . '/signature.txt';
        if (file_exists($signaturePath)) {
            $signature = file_get_contents($signaturePath);
        }
        
        $manifest = null;
        $manifestPath = $pluginPath . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
        }
        
        return ['data' => [
            'id' => $plugin->id->value,
            'name' => $plugin->name,
            'description' => $plugin->description ?? '',
            'version' => $plugin->version->value,
            'author' => $plugin->author ?? '',
            'state' => $plugin->state->value,
            'dependencies' => $plugin->dependencies ?? [],
            'hooks' => $plugin->hooks ?? [],
            'changelog' => $changelog,
            'signature' => $signature,
            'manifest' => $manifest,
        ]];
    }

    public function activatePlugin(string $id): array
    {
        try {
            $pluginId = \Click\Cms\Domain\ValueObjects\PluginId::fromString($id);
        } catch (\Exception $e) {
            return ['error' => 'Invalid plugin ID', 'status' => 400];
        }
        
        $result = $this->pluginManager->activate($pluginId);
        
        if (!$result['success']) {
            return ['error' => $result['error'], 'status' => 400];
        }
        
        return ['data' => ['activated' => true, 'id' => $id]];
    }

    public function deactivatePlugin(string $id): array
    {
        try {
            $pluginId = \Click\Cms\Domain\ValueObjects\PluginId::fromString($id);
        } catch (\Exception $e) {
            return ['error' => 'Invalid plugin ID', 'status' => 400];
        }
        
        $result = $this->pluginManager->deactivate($pluginId);
        
        if (!$result['success']) {
            return ['error' => $result['error'], 'status' => 400];
        }
        
        return ['data' => ['deactivated' => true, 'id' => $id]];
    }

    public function getPages(): array
    {
        $contentService = $this->pluginManager->getContentService();
        $pages = $contentService->pages();
        
        return [
            'data' => array_map(fn($p) => $p->toArray(), $pages),
        ];
    }

    public function getPage(string $slug): array
    {
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }
        
        return ['data' => $page->toArray()];
    }

    public function getPageVersions(string $slug): array
    {
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);

        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }

        $versions = $page->data['_versions'] ?? [];
        if (!is_array($versions)) {
            $versions = [];
        }

        $list = array_map(fn($v) => [
            'id' => $v['id'] ?? null,
            'created_at' => $v['created_at'] ?? null,
        ], $versions);

        return ['data' => $list];
    }

    public function getPageVersion(string $slug, string $versionId): array
    {
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);

        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }

        $versions = $page->data['_versions'] ?? [];
        if (!is_array($versions)) {
            $versions = [];
        }

        foreach ($versions as $version) {
            if (($version['id'] ?? null) === $versionId) {
                return ['data' => $version];
            }
        }

        return ['error' => 'Version not found', 'status' => 404];
    }

    public function restorePageVersion(string $slug, string $versionId): array
    {
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);

        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }

        $versions = $page->data['_versions'] ?? [];
        if (!is_array($versions)) {
            $versions = [];
        }

        $target = null;
        foreach ($versions as $version) {
            if (($version['id'] ?? null) === $versionId) {
                $target = $version;
                break;
            }
        }

        if ($target === null) {
            return ['error' => 'Version not found', 'status' => 404];
        }

        $restoredData = $target['data'] ?? [];
        if (!is_array($restoredData)) {
            $restoredData = [];
        }

        $restoredData['_versions'] = $versions;

        $restored = \Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::page($slug),
            $restoredData
        );

        $contentService->save($restored);

        return ['data' => $restored->toArray()];
    }

    public function createPage(): array
    {
        $data = $this->getJsonBody();
        
        if (!isset($data['title']) && !isset($data['content'])) {
            return ['error' => 'Title or content required', 'status' => 400];
        }

        $slugInput = trim((string) ($data['slug'] ?? ''));
        if ($slugInput !== '') {
            $slug = $this->slugify($slugInput);
        } else {
            $slug = $this->slugify($data['title'] ?? 'untitled');
        }

        if ($slug === '') {
            $slug = 'untitled';
        }
        
        $contentService = $this->pluginManager->getContentService();
        $user = $this->getSessionUser();

        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }
        
        if ($contentService->page($slug)) {
            return ['error' => 'Page already exists', 'status' => 409];
        }

        if (!isset($data['owner'])) {
            $data['owner'] = $user['username'] ?? 'unknown';
        }

        $content = \Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::page($slug),
            $data
        );
        
        $contentService->save($content);
        
        $this->pluginManager->executeHook('page_save', [
            'slug' => $slug,
            'title' => $data['title'] ?? '',
        ]);
        
        return ['data' => $content->toArray(), 'status' => 201];
    }

    public function updatePage(string $slug): array
    {
        $data = $this->getJsonBody();
        $contentService = $this->pluginManager->getContentService();
        $user = $this->getSessionUser();
        
        $page = $contentService->page($slug);
        
        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }

        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $permission = $this->canModifyPage($page->data, $user);
        if ($permission !== true) {
            return ['error' => $permission, 'status' => 403];
        }

        if (!isset($page->data['owner']) && isset($user['username'])) {
            $data['owner'] = $user['username'];
        } else {
            $data['owner'] = $page->data['owner'] ?? ($user['username'] ?? 'unknown');
        }

        $updated = $page->update($data);
        $contentService->save($updated);
        
        $this->pluginManager->executeHook('page_save', [
            'slug' => $slug,
            'title' => $data['title'] ?? '',
        ]);
        
        return ['data' => $updated->toArray()];
    }

    public function deletePage(string $slug): array
    {
        $contentService = $this->pluginManager->getContentService();
        $user = $this->getSessionUser();
        
        if (!$contentService->page($slug)) {
            return ['error' => 'Page not found', 'status' => 404];
        }

        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $page = $contentService->page($slug);
        $permission = $this->canDeletePage($page?->data ?? [], $user);
        if ($permission !== true) {
            return ['error' => $permission, 'status' => 403];
        }

        $contentService->delete(\Click\Cms\Domain\ValueObjects\ContentKey::page($slug));
        
        return ['data' => ['deleted' => true, 'slug' => $slug]];
    }

    private function getSessionUser(): ?array
    {
        $sessionFile = $this->pluginManager->getBasePath() . '/data/session.json';
        if (!file_exists($sessionFile)) {
            return null;
        }

        $session = json_decode(file_get_contents($sessionFile), true);
        if (!is_array($session)) {
            return null;
        }

        $expiresAt = $session['expiresAt'] ?? null;
        if ($expiresAt !== null && time() > (int) $expiresAt) {
            return null;
        }

        return $session['user'] ?? null;
    }

    private function canModifyPage(array $pageData, array $user): bool|string
    {
        $role = $user['role'] ?? 'editor';
        $owner = $pageData['owner'] ?? null;

        if ($role === 'admin') {
            return true;
        }

        if ($role === 'editor') {
            return true;
        }

        if ($role === 'author') {
            if ($owner === null) {
                return 'Page has no owner assigned.';
            }
            if ($owner !== ($user['username'] ?? null)) {
                return 'You can only edit your own pages.';
            }
            return true;
        }

        return 'Insufficient permissions.';
    }

    private function canDeletePage(array $pageData, array $user): bool|string
    {
        $role = $user['role'] ?? 'editor';

        if ($role === 'admin') {
            return true;
        }

        if ($role === 'editor') {
            return true;
        }

        if ($role === 'author') {
            $owner = $pageData['owner'] ?? null;
            if ($owner === null) {
                return 'Page has no owner assigned.';
            }
            if ($owner !== ($user['username'] ?? null)) {
                return 'You can only delete your own pages.';
            }
            return true;
        }

        return 'Insufficient permissions.';
    }

    public function getUsers(): array
    {
        $contentService = $this->pluginManager->getContentService();
        $users = $contentService->all('user');
        
        return [
            'data' => array_map(fn($u) => $this->sanitizeUser($u->toArray()), $users),
        ];
    }

    public function getUser(string $username): array
    {
        $contentService = $this->pluginManager->getContentService();
        $user = $contentService->user($username);
        
        if (!$user) {
            return ['error' => 'User not found', 'status' => 404];
        }
        
        return ['data' => $this->sanitizeUser($user->toArray())];
    }

    public function createUser(): array
    {
        $data = $this->getJsonBody();
        
        if (!isset($data['email'])) {
            return ['error' => 'Email required', 'status' => 400];
        }

        if (empty($data['password'])) {
            return ['error' => 'Password required', 'status' => 400];
        }

        $passwordValidation = $this->validatePassword($data['password']);
        if ($passwordValidation !== null) {
            return ['error' => $passwordValidation, 'status' => 400];
        }

        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        $username = $data['username'] ?? $this->slugify($data['email']);
        
        $contentService = $this->pluginManager->getContentService();
        
        if ($contentService->user($username)) {
            return ['error' => 'User already exists', 'status' => 409];
        }

        $content = \Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::user($username),
            $data
        );
        
        $contentService->save($content);
        
        $this->pluginManager->executeHook('user_create', [
            'username' => $username,
            'email' => $data['email'] ?? '',
            'role' => $data['role'] ?? 'editor',
        ]);
        
        return ['data' => $content->toArray(), 'status' => 201];
    }

    public function updateUser(string $username): array
    {
        $data = $this->getJsonBody();
        $contentService = $this->pluginManager->getContentService();
        
        $user = $contentService->user($username);
        
        if (!$user) {
            return ['error' => 'User not found', 'status' => 404];
        }

        if (isset($data['password']) && $data['password'] !== '') {
            $passwordValidation = $this->validatePassword($data['password']);
            if ($passwordValidation !== null) {
                return ['error' => $passwordValidation, 'status' => 400];
            }
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['password']);
        }

        $updated = $user->update($data);
        $contentService->save($updated);

        $oldData = $user->toArray();
        $changes = [];
        foreach ($data as $key => $value) {
            if (($oldData[$key] ?? null) !== $value) {
                $changes[$key] = ['old' => $oldData[$key] ?? null, 'new' => $value];
            }
        }
        
        if (isset($data['role']) && $data['role'] !== ($oldData['role'] ?? '')) {
            $this->pluginManager->executeHook('user_role_change', [
                'username' => $username,
                'old_role' => $oldData['role'] ?? '',
                'new_role' => $data['role'],
            ]);
        }
        
        if (isset($data['status']) && $data['status'] !== ($oldData['status'] ?? '')) {
            $this->pluginManager->executeHook('user_status_change', [
                'username' => $username,
                'old_status' => $oldData['status'] ?? '',
                'new_status' => $data['status'],
            ]);
        }
        
        $this->pluginManager->executeHook('user_update', [
            'username' => $username,
            'changes' => $changes,
        ]);
        
        return ['data' => $this->sanitizeUser($updated->toArray())];
    }

    public function deleteUser(string $username): array
    {
        $contentService = $this->pluginManager->getContentService();
        
        if (!$contentService->user($username)) {
            return ['error' => 'User not found', 'status' => 404];
        }

        $contentService->delete(\Click\Cms\Domain\ValueObjects\ContentKey::user($username));
        
        $this->pluginManager->executeHook('user_delete', [
            'username' => $username,
            'deleted_by' => $_SESSION['user']['username'] ?? 'system',
        ]);
        
        return ['data' => ['deleted' => true, 'username' => $username]];
    }

    public function getMedia(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $mediaPath = $basePath . '/content/media/uploads';
        
        $files = [];
        
        if (!is_dir($mediaPath)) {
            return ['data' => []];
        }

        $items = scandir($mediaPath);
        if ($items === false) {
            return ['data' => []];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $filePath = $mediaPath . '/' . $item;
            if (!is_file($filePath)) continue;

            $metadataPath = $basePath . '/content/media/' . $item . '.json';
            
            if (file_exists($metadataPath)) {
                $metadata = json_decode(file_get_contents($metadataPath), true);
                if (is_array($metadata)) {
                    $metadata['url'] = '/media/uploads/' . $item;
                    $metadata['thumbnail_url'] = '/media/thumbnails/' . $item;
                    $files[] = $metadata;
                }
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                $files[] = [
                    'filename' => $item,
                    'original_name' => $item,
                    'size' => filesize($filePath),
                    'mime_type' => $this->getMimeType($filePath),
                    'dimensions' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) 
                        ? @getimagesize($filePath) 
                        : null,
                    'alt' => pathinfo($item, PATHINFO_FILENAME),
                    'caption' => '',
                    'created_at' => date('c', filemtime($filePath)),
                    'url' => '/media/uploads/' . $item,
                    'thumbnail_url' => '/media/thumbnails/' . $item,
                ];
            }
        }

        usort($files, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return ['data' => $files];
    }

    public function getMediaFile(string $filename): array
    {
        $contentService = $this->pluginManager->getContentService();
        $media = $contentService->get(\Click\Cms\Domain\ValueObjects\ContentKey::media($filename));
        
        if (!$media) {
            return ['error' => 'Media not found', 'status' => 404];
        }
        
        return ['data' => $media->toArray()];
    }

    public function uploadMedia(): array
    {
        $user = $this->getSessionUser();
        
        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $role = $user['role'] ?? 'editor';
        if ($role !== 'admin' && $role !== 'editor') {
            return ['error' => 'Insufficient permissions', 'status' => 403];
        }

        if (!isset($_FILES['file'])) {
            return ['error' => 'No file uploaded', 'status' => 400];
        }

        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload error: ' . $file['error'], 'status' => 400];
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'mp4', 'webm', 'mp3', 'wav', 'zip'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowedExtensions)) {
            return ['error' => 'File type not allowed', 'status' => 400];
        }

        $basePath = $this->pluginManager->getBasePath();
        $mediaPath = $basePath . '/content/media/uploads';
        
        if (!is_dir($mediaPath)) {
            mkdir($mediaPath, 0755, true);
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filename = preg_replace('/_+/', '_', $filename);
        $filename = trim($filename, '_');

        $counter = 1;
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $originalFilename = $filename;
        
        while (file_exists($mediaPath . '/' . $filename)) {
            $filename = $baseName . '_' . $counter . '.' . $ext;
            $counter++;
        }

        $destination = $mediaPath . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['error' => 'Failed to save file', 'status' => 500];
        }

        $metadata = [
            'original_name' => $file['name'],
            'filename' => $filename,
            'size' => filesize($destination),
            'mime_type' => $this->getMimeType($destination),
            'dimensions' => null,
            'alt' => pathinfo($filename, PATHINFO_FILENAME),
            'caption' => '',
            'created_at' => date('c'),
            'uploaded_by' => $user['username'] ?? 'unknown',
        ];

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $dimensions = @getimagesize($destination);
            if ($dimensions !== false) {
                $metadata['dimensions'] = [
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                ];
            }
            
            $this->createThumbnail($destination, $filename, $ext, $basePath);
        }

        $metadataPath = $basePath . '/content/media/' . $filename . '.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));

        return [
            'data' => array_merge($metadata, [
                'url' => '/media/uploads/' . $filename,
                'thumbnail_url' => '/media/thumbnails/' . $filename,
            ]),
            'status' => 201
        ];
    }

    public function updateMediaMetadata(string $filename): array
    {
        $user = $this->getSessionUser();
        
        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $role = $user['role'] ?? 'editor';
        if ($role !== 'admin' && $role !== 'editor') {
            return ['error' => 'Insufficient permissions', 'status' => 403];
        }

        $data = $this->getJsonBody();
        $basePath = $this->pluginManager->getBasePath();
        $metadataPath = $basePath . '/content/media/' . $filename . '.json';

        if (!file_exists($metadataPath)) {
            return ['error' => 'File not found', 'status' => 404];
        }

        $metadata = json_decode(file_get_contents($metadataPath), true);
        
        if (isset($data['alt'])) {
            $metadata['alt'] = $data['alt'];
        }
        if (isset($data['caption'])) {
            $metadata['caption'] = $data['caption'];
        }

        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));

        return ['data' => array_merge($metadata, [
            'url' => '/media/uploads/' . $filename,
            'thumbnail_url' => '/media/thumbnails/' . $filename,
        ])];
    }

    public function deleteMediaFile(string $filename): array
    {
        $user = $this->getSessionUser();
        
        if ($user === null) {
            return ['error' => 'Not authenticated', 'status' => 401];
        }

        $role = $user['role'] ?? 'editor';
        if ($role !== 'admin') {
            return ['error' => 'Only admins can delete media', 'status' => 403];
        }

        $basePath = $this->pluginManager->getBasePath();
        $mediaPath = $basePath . '/content/media/uploads/' . $filename;
        $thumbPath = $basePath . '/content/media/thumbnails/' . $filename;
        $metadataPath = $basePath . '/content/media/' . $filename . '.json';

        $deleted = false;

        if (file_exists($mediaPath)) {
            unlink($mediaPath);
            $deleted = true;
        }

        if (file_exists($thumbPath)) {
            unlink($thumbPath);
        }

        if (file_exists($metadataPath)) {
            unlink($metadataPath);
        }

        if (!$deleted) {
            return ['error' => 'File not found', 'status' => 404];
        }

        return ['data' => ['deleted' => true, 'filename' => $filename]];
    }

    public function serveMediaFile(string $filename): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $filePath = $basePath . '/content/media/uploads/' . $filename;

        if (!file_exists($filePath)) {
            return ['status' => 404, 'error' => 'Not found'];
        }

        $mimeType = $this->getMimeType($filePath);
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=31536000');
        
        readfile($filePath);
        return ['raw' => true];
    }

    public function serveThumbnail(string $filename): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $thumbPath = $basePath . '/content/media/thumbnails/' . $filename;
        $originalPath = $basePath . '/content/media/uploads/' . $filename;
        
        if (!file_exists($thumbPath) && file_exists($originalPath)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $this->createThumbnail($originalPath, $filename, $ext, $basePath);
            }
        }

        if (!file_exists($thumbPath)) {
            return ['status' => 404, 'error' => 'Not found'];
        }

        $mimeType = $this->getMimeType($thumbPath);
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=31536000');
        
        readfile($thumbPath);
        return ['raw' => true];
    }

    private function getMimeType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'zip' => 'application/zip',
        ];

        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }

    private function createThumbnail(string $sourcePath, string $filename, string $ext, string $basePath): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $thumbPath = $basePath . '/content/media/thumbnails/' . $filename;
        $thumbDir = dirname($thumbPath);
        
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $maxWidth = 400;
        $maxHeight = 400;

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case 'png':
                $source = imagecreatefrompng($sourcePath);
                break;
            case 'gif':
                $source = imagecreatefromgif($sourcePath);
                break;
            case 'webp':
                $source = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }

        if ($source === false) {
            return false;
        }

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
        
        if ($ratio >= 1) {
            $thumbWidth = $srcWidth;
            $thumbHeight = $srcHeight;
        } else {
            $thumbWidth = (int) ($srcWidth * $ratio);
            $thumbHeight = (int) ($srcHeight * $ratio);
        }

        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        
        if ($ext === 'png' || $ext === 'gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        }

        imagecopyresampled(
            $thumb, $source,
            0, 0, 0, 0,
            $thumbWidth, $thumbHeight,
            $srcWidth, $srcHeight
        );

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $result = imagejpeg($thumb, $thumbPath, 85);
                break;
            case 'png':
                $result = imagepng($thumb, $thumbPath, 6);
                break;
            case 'gif':
                $result = imagegif($thumb, $thumbPath);
                break;
            case 'webp':
                $result = imagewebp($thumb, $thumbPath, 85);
                break;
            default:
                $result = false;
        }

        imagedestroy($source);
        imagedestroy($thumb);

        return $result;
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

    private function sanitizeUser(array $user): array
    {
        if (isset($user['data']['password'])) {
            $user['data']['password'] = null;
        }

        if (isset($user['password'])) {
            $user['password'] = null;
        }

        return $user;
    }

    private function validatePassword(string $password): ?string
    {
        $minLength = 8;

        if (strlen($password) < $minLength) {
            return 'Password must be at least ' . $minLength . ' characters.';
        }

        if (strlen($password) > 128) {
            return 'Password must be less than 128 characters.';
        }

        return null;
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    public function getPluginDependencies(): array
    {
        $plugins = $this->pluginManager->all();
        $PluginState = \Click\Cms\Domain\Plugin\PluginState::class;
        
        $graph = [];
        
        foreach ($plugins as $plugin) {
            $dependencies = $plugin->dependencies ?? [];
            
            $depDetails = [];
            foreach ($dependencies as $dep) {
                try {
                    $depPlugin = $this->pluginManager->get(\Click\Cms\Domain\ValueObjects\PluginId::fromString($dep));
                    $depDetails[] = [
                        'id' => $dep,
                        'installed' => $depPlugin !== null,
                        'activated' => $depPlugin !== null && $depPlugin->state === $PluginState::ACTIVATED,
                    ];
                } catch (\Exception $e) {
                    $depDetails[] = [
                        'id' => $dep,
                        'installed' => false,
                        'activated' => false,
                    ];
                }
            }
            
            $graph[] = [
                'id' => $plugin->id->value,
                'name' => $plugin->name,
                'dependencies' => $depDetails,
                'dependents' => [],
            ];
        }
        
        foreach ($graph as &$node) {
            foreach ($node['dependencies'] as $dep) {
                foreach ($graph as &$depNode) {
                    if ($depNode['id'] === $dep['id']) {
                        $depNode['dependents'][] = $node['id'];
                    }
                }
            }
        }
        
        return ['data' => $graph];
    }
}
