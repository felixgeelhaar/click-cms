<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_structured_logging extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $requestId = '';
    private string $logFile = '';
    private array $context = [];

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        
        $basePath = $pluginManager->getBasePath();
        $this->logFile = $basePath . '/data/logs/app.log';
        
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public function getPluginId(): string
    {
        return 'structured-logging';
    }

    public function getPluginName(): string
    {
        return 'Structured Logging';
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
        $this->requestId = $this->generateRequestId();
        
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $this->context['parent_id'] = $_SERVER['HTTP_X_REQUEST_ID'];
        }
        
        $this->context['request_uri'] = $_SERVER['REQUEST_URI'] ?? '/';
        $this->context['request_method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->context['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $this->log('info', 'Request started', [
            'type' => 'request_start',
        ]);
    }

    public function hook_request_end(array $params): void
    {
        $this->log('info', 'Request completed', [
            'type' => 'request_end',
            'status' => $params['status'] ?? 200,
            'duration_ms' => $params['duration'] ?? 0,
        ]);
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/logs' => [$this, 'serveLogs'],
            'GET /api/request-id' => [$this, 'serveRequestId'],
        ];
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    private function generateRequestId(): string
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }
        
        return uniqid('req_', true);
    }

    public function log(string $level, string $message, array $extra = []): void
    {
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'request_id' => $this->requestId,
            'context' => array_merge($this->context, $extra),
        ];

        if (function_exists('json_encode')) {
            $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            $logLine = serialize($entry) . "\n";
        }

        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);

        if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            error_log("[{$level}] {$message}");
        }
    }

    public function logError(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function logWarning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function logInfo(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function logDebug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function serveLogs(): array
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $level = $_GET['level'] ?? null;
        $requestId = $_GET['request_id'] ?? null;
        
        $logs = [];
        
        if (file_exists($this->logFile)) {
            $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);
            
            foreach ($lines as $line) {
                if (count($logs) >= $limit) {
                    break;
                }
                
                $entry = json_decode($line, true);
                if (!$entry) {
                    continue;
                }
                
                if ($level && ($entry['level'] ?? '') !== $level) {
                    continue;
                }
                
                if ($requestId && ($entry['request_id'] ?? '') !== $requestId) {
                    continue;
                }
                
                $logs[] = $entry;
            }
        }
        
        return ['data' => $logs];
    }

    public function serveRequestId(): array
    {
        return [
            'request_id' => $this->requestId,
        ];
    }
}
