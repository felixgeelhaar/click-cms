<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_cache extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $driver = 'file';
    /** @var \Redis|null */
    private $redis = null;
    /** @var \Memcache|null */
    private $memcache = null;
    private string $cacheDir = '';
    protected array $config = [];

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->cacheDir = $basePath . '/data/cache';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        $this->loadConfig();
    }

    public function getPluginId(): string
    {
        return 'cache';
    }

    public function getPluginName(): string
    {
        return 'Caching';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        $this->connect();
        return true;
    }

    public function deactivate(): bool
    {
        $this->disconnect();
        return true;
    }

    public function hook_request_start(array $params): void
    {
        $this->serveCachedResponse();
    }

    public function hook_page_save(array $params): void
    {
        $this->invalidatePageCache($params['slug'] ?? '');
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/cache/stats' => [$this, 'getCacheStats'],
            'POST /api/cache/clear' => [$this, 'clearCache'],
            'POST /api/cache/invalidate' => [$this, 'invalidateCache'],
        ];
    }

    private function loadConfig(): void
    {
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/config/cache.json';
        
        $defaults = [
            'driver' => 'file',
            'ttl' => 3600,
            'redis' => [
                'host' => 'localhost',
                'port' => 6379,
                'prefix' => 'click:',
            ],
            'memcache' => [
                'host' => 'localhost',
                'port' => 11211,
            ],
        ];
        
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $this->config = array_merge($defaults, $config ?? []);
        } else {
            $this->config = $defaults;
        }
        
        $this->driver = $this->config['driver'] ?? 'file';
    }

    private function connect(): void
    {
        if ($this->driver === 'redis' && class_exists('Redis')) {
            try {
                $this->redis = new \Redis();
                $this->redis->connect(
                    $this->config['redis']['host'] ?? 'localhost',
                    $this->config['redis']['port'] ?? 6379
                );
            } catch (\Exception $e) {
                error_log('Redis connection failed: ' . $e->getMessage());
                $this->driver = 'file';
            }
        } elseif ($this->driver === 'memcache' && class_exists('Memcache')) {
            try {
                $this->memcache = new \Memcache();
                $this->memcache->connect(
                    $this->config['memcache']['host'] ?? 'localhost',
                    $this->config['memcache']['port'] ?? 11211
                );
            } catch (\Exception $e) {
                error_log('Memcache connection failed: ' . $e->getMessage());
                $this->driver = 'file';
            }
        }
    }

    private function disconnect(): void
    {
        $this->redis = null;
        $this->memcache = null;
    }

    private function serveCachedResponse(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        if (str_starts_with($uri, '/api/')) {
            return;
        }
        
        if (str_starts_with($uri, '/admin')) {
            return;
        }
        
        $cacheKey = $this->getCacheKey('page', $uri);
        $cached = $this->get($cacheKey);
        
        if ($cached !== null) {
            header('X-Cache: HIT');
            header('Content-Type: text/html; charset=utf-8');
            echo $cached;
            exit;
        }
        
        header('X-Cache: MISS');
    }

    public function get(string $key): ?string
    {
        if ($this->driver === 'redis' && $this->redis) {
            $value = $this->redis->get($key);
            return $value !== false ? $value : null;
        } elseif ($this->driver === 'memcache' && $this->memcache) {
            $value = $this->memcache->get($key);
            return $value !== false ? $value : null;
        } else {
            $file = $this->cacheDir . '/' . md5($key) . '.cache';
            if (!file_exists($file)) {
                return null;
            }
            
            $data = json_decode(file_get_contents($file), true);
            if (!$data || $data['expires'] < time()) {
                @unlink($file);
                return null;
            }
            
            return $data['value'];
        }
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $ttl = $ttl ?? ($this->config['ttl'] ?? 3600);
        
        if ($this->driver === 'redis' && $this->redis) {
            $prefix = $this->config['redis']['prefix'] ?? 'click:';
            $this->redis->setex($prefix . $key, $ttl, $value);
        } elseif ($this->driver === 'memcache' && $this->memcache) {
            $this->memcache->set($key, $value, 0, $ttl);
        } else {
            $file = $this->cacheDir . '/' . md5($key) . '.cache';
            $data = [
                'value' => $value,
                'expires' => time() + $ttl,
            ];
            file_put_contents($file, json_encode($data));
        }
    }

    public function delete(string $key): void
    {
        if ($this->driver === 'redis' && $this->redis) {
            $prefix = $this->config['redis']['prefix'] ?? 'click:';
            $this->redis->del($prefix . $key);
        } elseif ($this->driver === 'memcache' && $this->memcache) {
            $this->memcache->delete($key);
        } else {
            $file = $this->cacheDir . '/' . md5($key) . '.cache';
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    public function clear(): void
    {
        if ($this->driver === 'redis' && $this->redis) {
            $prefix = $this->config['redis']['prefix'] ?? 'click:';
            $keys = $this->redis->keys($prefix . '*');
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        } elseif ($this->driver === 'memcache' && $this->memcache) {
            $this->memcache->flush();
        } else {
            $files = glob($this->cacheDir . '/*.cache');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }

    private function getCacheKey(string $type, string $identifier): string
    {
        return $type . ':' . md5($identifier);
    }

    private function invalidatePageCache(string $slug): void
    {
        if (!empty($slug)) {
            $this->delete($this->getCacheKey('page', '/' . $slug));
        }
    }

    public function getCacheStats(): array
    {
        $stats = [
            'driver' => $this->driver,
            'enabled' => true,
        ];
        
        if ($this->driver === 'redis' && $this->redis) {
            $info = $this->redis->info('memory');
            $stats['memory_used'] = $info['used_memory_human'] ?? 'unknown';
            $stats['keys'] = $this->redis->dbSize();
        } elseif ($this->driver === 'memcache' && $this->memcache) {
            $stats['version'] = $this->memcache->getVersion();
        } else {
            $files = glob($this->cacheDir . '/*.cache');
            $stats['files'] = count($files);
            
            $totalSize = 0;
            foreach ($files as $file) {
                $totalSize += filesize($file);
            }
            $stats['size_bytes'] = $totalSize;
        }
        
        return ['data' => $stats];
    }

    public function clearCache(): array
    {
        $this->clear();
        return ['data' => ['cleared' => true, 'timestamp' => time()]];
    }

    public function invalidateCache(): array
    {
        $data = $this->getJsonBody();
        $key = $data['key'] ?? '';
        
        if (empty($key)) {
            return ['error' => 'Cache key required', 'status' => 400];
        }
        
        $this->delete($key);
        return ['data' => ['invalidated' => true, 'key' => $key]];
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
