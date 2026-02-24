<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_metrics extends \Click\Cms\Application\Plugin\BasePlugin
{
    private $metrics = [];
    
    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
    }

    public function getPluginId(): string
    {
        return 'metrics';
    }

    public function getPluginName(): string
    {
        return 'Metrics';
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
            'GET /metrics' => [$this, 'serveMetrics'],
            'GET /api/metrics' => [$this, 'serveMetricsJson'],
        ];
    }

    public function hook_request_start(array $params): void
    {
        $this->increment('http_requests_total', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    }

    public function hook_request_end(array $params): void
    {
        $status = $params['status'] ?? 200;
        $this->increment('http_requests_by_status_total', ['status' => (string)$status]);
        $duration = $params['duration'] ?? 0;
        $this->observe('http_request_duration_seconds', $duration);
    }

    public function serveMetrics(): array
    {
        $this->collectSystemMetrics();
        
        $output = '';
        
        foreach ($this->metrics as $name => $data) {
            if ($data['type'] === 'counter') {
                $output .= "# TYPE $name counter\n";
                foreach ($data['values'] as $labels => $value) {
                    $output .= "$name{$labels} $value\n";
                }
            } elseif ($data['type'] === 'gauge') {
                $output .= "# TYPE $name gauge\n";
                foreach ($data['values'] as $labels => $value) {
                    $output .= "$name{$labels} $value\n";
                }
            } elseif ($data['type'] === 'histogram') {
                $output .= "# TYPE $name histogram\n";
                foreach ($data['buckets'] as $labels => $buckets) {
                    $output .= "${name}_bucket{$labels} {$buckets['count']}\n";
                }
                $output .= "_sum{$labels} {$data['sum']}\n";
                $output .= "_count{$labels} {$data['count']}\n";
            }
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        echo $output;
        
        return ['raw' => true];
    }

    public function serveMetricsJson(): array
    {
        $this->collectSystemMetrics();
        
        return ['data' => $this->metrics];
    }

    private function collectSystemMetrics(): void
    {
        $basePath = $this->pluginManager->getBasePath();
        
        $this->set('cms_pages_total', $this->countFiles($basePath . '/content/page'));
        $this->set('cms_users_total', $this->countFiles($basePath . '/content/users'));
        $this->set('cms_plugins_total', count($this->pluginManager->all()));
        
        $pluginsPath = $basePath . '/plugins';
        $pluginDirs = is_dir($pluginsPath) ? glob($pluginsPath . '/*', GLOB_ONLYDIR) : [];
        $this->set('cms_plugins_installed_total', count($pluginDirs));
        
        if (function_exists('memory_get_usage')) {
            $this->set('cms_memory_usage_bytes', memory_get_usage(true));
        }
        
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $this->set('cms_load_avg_1min', $load[0]);
            $this->set('cms_load_avg_5min', $load[1]);
            $this->set('cms_load_avg_15min', $load[2]);
        }
    }

    private function countFiles(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        
        $count = 0;
        $files = glob($path . '/*.json');
        if ($files) {
            $count = count($files);
        }
        
        return $count;
    }

    public function increment(string $name, array $labels = [], float $value = 1): void
    {
        $key = $this->getMetricKey($name, $labels);
        
        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = [
                'type' => 'counter',
                'values' => [],
            ];
        }
        
        if (!isset($this->metrics[$name]['values'][$key])) {
            $this->metrics[$name]['values'][$key] = 0;
        }
        
        $this->metrics[$name]['values'][$key] += $value;
    }

    public function set(string $name, float $value, array $labels = []): void
    {
        $key = $this->getMetricKey($name, $labels);
        
        $this->metrics[$name] = [
            'type' => 'gauge',
            'values' => [
                $key => $value,
            ],
        ];
    }

    public function observe(string $name, float $value, array $labels = []): void
    {
        $key = $this->getMetricKey($name, $labels);
        
        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = [
                'type' => 'histogram',
                'values' => [],
                'buckets' => [],
                'sum' => 0,
                'count' => 0,
            ];
        }
        
        $this->metrics[$name]['sum'] += $value;
        $this->metrics[$name]['count'] += 1;
        
        $buckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];
        
        foreach ($buckets as $bucket) {
            $bucketKey = $key . ',le=' . $bucket;
            if (!isset($this->metrics[$name]['buckets'][$bucketKey])) {
                $this->metrics[$name]['buckets'][$bucketKey] = ['count' => 0];
            }
            if ($value <= $bucket) {
                $this->metrics[$name]['buckets'][$bucketKey]['count']++;
            }
        }
    }

    private function getMetricKey(string $name, array $labels): string
    {
        if (empty($labels)) {
            return '';
        }
        
        $labelParts = [];
        foreach ($labels as $k => $v) {
            $labelParts[] = "$k=\"$v\"";
        }
        
        return '{' . implode(',', $labelParts) . '}';
    }
}
