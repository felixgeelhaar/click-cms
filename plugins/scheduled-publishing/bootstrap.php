<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_scheduled_publishing extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $jobsFile = '';
    private string $lastRunFile = '';

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->jobsFile = $basePath . '/data/scheduled-jobs.json';
        $this->lastRunFile = $basePath . '/data/scheduled-last-run.json';
    }

    public function getPluginId(): string
    {
        return 'scheduled-publishing';
    }

    public function getPluginName(): string
    {
        return 'Scheduled Publishing';
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
        $this->processScheduledJobs();
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/scheduled' => [$this, 'getScheduledContent'],
            'POST /api/scheduled/publish' => [$this, 'schedulePublish'],
            'POST /api/scheduled/unpublish' => [$this, 'scheduleUnpublish'],
            'DELETE /api/scheduled/:id' => [$this, 'cancelScheduled'],
            'POST /api/scheduled/process' => [$this, 'processNow'],
        ];
    }

    private function processScheduledJobs(): void
    {
        $lastRun = $this->getLastRunTime();
        $now = time();
        
        if ($now - $lastRun < 60) {
            return;
        }
        
        $this->setLastRunTime($now);
        
        $basePath = $this->pluginManager->getBasePath();
        $pagesPath = $basePath . '/content/page';
        
        if (!is_dir($pagesPath)) {
            return;
        }
        
        $files = glob($pagesPath . '/*.json');
        if (!$files) {
            return;
        }
        
        $contentService = $this->pluginManager->getContentService();
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) {
                continue;
            }
            
            $scheduledPublish = $data['scheduled_publish'] ?? null;
            if ($scheduledPublish) {
                $publishTime = strtotime($scheduledPublish);
                if ($publishTime && $now >= $publishTime) {
                    $data['status'] = 'published';
                    unset($data['scheduled_publish']);
                    
                    $slug = basename($file, '.json');
                    $content = $contentService->page($slug);
                    if ($content) {
                        $updated = $content->update($data);
                        $contentService->save($updated);
                    }
                }
            }
            
            $scheduledUnpublish = $data['scheduled_unpublish'] ?? null;
            if ($scheduledUnpublish) {
                $unpublishTime = strtotime($scheduledUnpublish);
                if ($unpublishTime && $now >= $unpublishTime) {
                    $data['status'] = 'draft';
                    unset($data['scheduled_unpublish']);
                    
                    $slug = basename($file, '.json');
                    $content = $contentService->page($slug);
                    if ($content) {
                        $updated = $content->update($data);
                        $contentService->save($updated);
                    }
                }
            }
        }
    }

    private function getLastRunTime(): int
    {
        if (!file_exists($this->lastRunFile)) {
            return 0;
        }
        
        $data = json_decode(file_get_contents($this->lastRunFile), true);
        return $data['last_run'] ?? 0;
    }

    private function setLastRunTime(int $time): void
    {
        $dir = dirname($this->lastRunFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->lastRunFile, json_encode(['last_run' => $time]));
    }

    public function getScheduledContent(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $pagesPath = $basePath . '/content/page';
        
        $scheduled = [];
        
        if (!is_dir($pagesPath)) {
            return ['data' => []];
        }
        
        $files = glob($pagesPath . '/*.json');
        if (!$files) {
            return ['data' => []];
        }
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) {
                continue;
            }
            
            $slug = basename($file, '.json');
            
            if (!empty($data['scheduled_publish'])) {
                $scheduled[] = [
                    'id' => 'publish-' . $slug,
                    'type' => 'publish',
                    'slug' => $slug,
                    'title' => $data['title'] ?? $slug,
                    'scheduled_time' => $data['scheduled_publish'],
                ];
            }
            
            if (!empty($data['scheduled_unpublish'])) {
                $scheduled[] = [
                    'id' => 'unpublish-' . $slug,
                    'type' => 'unpublish',
                    'slug' => $slug,
                    'title' => $data['title'] ?? $slug,
                    'scheduled_time' => $data['scheduled_unpublish'],
                ];
            }
        }
        
        usort($scheduled, function($a, $b) {
            return strcmp($a['scheduled_time'], $b['scheduled_time']);
        });
        
        return ['data' => $scheduled];
    }

    public function schedulePublish(): array
    {
        $data = $this->getJsonBody();
        
        $slug = $data['slug'] ?? '';
        $scheduledTime = $data['scheduled_time'] ?? '';
        
        if (empty($slug) || empty($scheduledTime)) {
            return ['error' => 'Slug and scheduled_time required', 'status' => 400];
        }
        
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }
        
        $pageData = $page->data;
        $pageData['scheduled_publish'] = $scheduledTime;
        unset($pageData['scheduled_unpublish']);
        
        $updated = $page->update($pageData);
        $contentService->save($updated);
        
        return ['data' => [
            'scheduled' => true,
            'type' => 'publish',
            'slug' => $slug,
            'scheduled_time' => $scheduledTime,
        ]];
    }

    public function scheduleUnpublish(): array
    {
        $data = $this->getJsonBody();
        
        $slug = $data['slug'] ?? '';
        $scheduledTime = $data['scheduled_time'] ?? '';
        
        if (empty($slug) || empty($scheduledTime)) {
            return ['error' => 'Slug and scheduled_time required', 'status' => 400];
        }
        
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }
        
        $pageData = $page->data;
        $pageData['scheduled_unpublish'] = $scheduledTime;
        unset($pageData['scheduled_publish']);
        
        $updated = $page->update($pageData);
        $contentService->save($updated);
        
        return ['data' => [
            'scheduled' => true,
            'type' => 'unpublish',
            'slug' => $slug,
            'scheduled_time' => $scheduledTime,
        ]];
    }

    public function cancelScheduled(string $id): array
    {
        $parts = explode('-', $id, 2);
        if (count($parts) !== 2) {
            return ['error' => 'Invalid ID', 'status' => 400];
        }
        
        [$type, $slug] = $parts;
        
        $contentService = $this->pluginManager->getContentService();
        $page = $contentService->page($slug);
        
        if (!$page) {
            return ['error' => 'Page not found', 'status' => 404];
        }
        
        $pageData = $page->data;
        
        if ($type === 'publish') {
            unset($pageData['scheduled_publish']);
        } elseif ($type === 'unpublish') {
            unset($pageData['scheduled_unpublish']);
        }
        
        $updated = $page->update($pageData);
        $contentService->save($updated);
        
        return ['data' => ['cancelled' => true, 'id' => $id]];
    }

    public function processNow(): array
    {
        $this->processScheduledJobs();
        
        return ['data' => ['processed' => true, 'timestamp' => time()]];
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
