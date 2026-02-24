<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_mysql_storage extends \Click\Cms\Application\Plugin\BasePlugin
{
    private ?object $pdo = null;

    public function getPluginId(): string
    {
        return 'mysql-storage';
    }

    public function getPluginName(): string
    {
        return 'MySQL Storage';
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
            'GET /api/storage/status' => [$this, 'getStatus'],
        ];
    }

    private function connect(): void
    {
        $config = $this->getMysqlConfig();
        
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? 'click_cms';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $this->pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            
            $this->initializeSchema();
        } catch (\PDOException $e) {
            error_log('MySQL connection failed: ' . $e->getMessage());
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
                data JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (type),
                INDEX idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            "CREATE TABLE IF NOT EXISTS content_versions (
                id VARCHAR(255) PRIMARY KEY,
                content_id VARCHAR(255) NOT NULL,
                data JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_content_id (content_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            "CREATE TABLE IF NOT EXISTS media (
                id VARCHAR(255) PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100),
                size INT,
                data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        ];
        
        foreach ($queries as $query) {
            try {
                $this->pdo->exec($query);
            } catch (\PDOException $e) {
                error_log('MySQL schema init failed: ' . $e->getMessage());
            }
        }
    }

    private function getMysqlConfig(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/config/storage.json';
        
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            return $config['mysql'] ?? [];
        }
        
        $envConfig = [
            'host' => getenv('CLICK_MYSQL_HOST') ?: 'localhost',
            'port' => getenv('CLICK_MYSQL_PORT') ?: 3306,
            'database' => getenv('CLICK_MYSQL_DATABASE') ?: 'click_cms',
            'username' => getenv('CLICK_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('CLICK_MYSQL_PASSWORD') ?: '',
        ];
        
        return array_filter($envConfig);
    }

    public function getStatus(): array
    {
        if (!$this->pdo) {
            return ['data' => [
                'connected' => false,
                'driver' => 'mysql',
                'error' => 'Not connected',
            ]];
        }
        
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM content");
            $result = $stmt->fetch();
            
            return ['data' => [
                'connected' => true,
                'driver' => 'mysql',
                'content_count' => $result['count'] ?? 0,
            ]];
        } catch (\PDOException $e) {
            return ['data' => [
                'connected' => false,
                'driver' => 'mysql',
                'error' => $e->getMessage(),
            ]];
        }
    }
}
