<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_postgres_storage extends \Click\Cms\Application\Plugin\BasePlugin
{
    private ?object $pdo = null;

    public function getPluginId(): string
    {
        return 'postgres-storage';
    }

    public function getPluginName(): string
    {
        return 'PostgreSQL Storage';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        $this->connect();
        return $this->pdo !== null;
    }

    public function deactivate(): bool
    {
        $this->pdo = null;
        return true;
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/storage/status' => [$this, 'getPostgresStatus'],
        ];
    }

    private function connect(): void
    {
        $config = $this->getPostgresConfig();
        
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? 'click_cms';
        $username = $config['username'] ?? 'postgres';
        $password = $config['password'] ?? '';
        
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $this->pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            
            $this->initializeSchema();
        } catch (\PDOException $e) {
            error_log('PostgreSQL connection failed: ' . $e->getMessage());
            $this->pdo = null;
        }
    }

    private function initializeSchema(): void
    {
        if (!$this->pdo) {
            return;
        }
        
        $queries = [
            "CREATE TABLE IF NOT EXISTS content (
                id VARCHAR(255) PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                data JSONB NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE TABLE IF NOT EXISTS content_versions (
                id VARCHAR(255) PRIMARY KEY,
                content_id VARCHAR(255) NOT NULL,
                data JSONB NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE TABLE IF NOT EXISTS media (
                id VARCHAR(255) PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100),
                size INTEGER,
                data JSONB,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_content_type ON content(type)",
            "CREATE INDEX IF NOT EXISTS idx_content_updated ON content(updated_at)",
            "CREATE INDEX IF NOT EXISTS idx_content_versions_content_id ON content_versions(content_id)",
            "CREATE INDEX IF NOT EXISTS idx_media_filename ON media(filename)",
        ];
        
        foreach ($queries as $query) {
            try {
                $this->pdo->exec($query);
            } catch (\PDOException $e) {
                error_log('PostgreSQL schema init failed: ' . $e->getMessage());
            }
        }
    }

    private function getPostgresConfig(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/config/storage.json';
        
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            return $config['postgres'] ?? [];
        }
        
        $envConfig = [
            'host' => getenv('CLICK_POSTGRES_HOST') ?: 'localhost',
            'port' => getenv('CLICK_POSTGRES_PORT') ?: 5432,
            'database' => getenv('CLICK_POSTGRES_DATABASE') ?: 'click_cms',
            'username' => getenv('CLICK_POSTGRES_USERNAME') ?: 'postgres',
            'password' => getenv('CLICK_POSTGRES_PASSWORD') ?: '',
        ];
        
        return array_filter($envConfig);
    }

    public function getPostgresStatus(): array
    {
        if (!$this->pdo) {
            return ['data' => [
                'connected' => false,
                'driver' => 'postgres',
                'error' => 'Not connected',
            ]];
        }
        
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM content");
            $result = $stmt->fetch();
            
            return ['data' => [
                'connected' => true,
                'driver' => 'postgres',
                'content_count' => $result['count'] ?? 0,
            ]];
        } catch (\PDOException $e) {
            return ['data' => [
                'connected' => false,
                'driver' => 'postgres',
                'error' => $e->getMessage(),
            ]];
        }
    }
}
