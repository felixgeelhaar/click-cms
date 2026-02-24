<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_admin_ui extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $adminDistPath;

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        $this->adminDistPath = $this->pluginManager->getBasePath() . '/admin-ui/dist';
    }

    public function getPluginId(): string
    {
        return 'admin-ui';
    }

    public function getPluginName(): string
    {
        return 'Admin UI';
    }

    public function hook_api_routes(array $params): array
    {
        return [
            'GET /admin' => [$this, 'serveAdmin'],
            'GET /admin/:path*' => [$this, 'serveAdminAsset'],
        ];
    }

    public function serveAdmin(): array
    {
        $indexPath = $this->adminDistPath . '/index.html';
        
        if (!file_exists($indexPath)) {
            return $this->renderBuildInstructions();
        }

        header('Content-Type: text/html');
        readfile($indexPath);
        return ['raw' => true];
    }

    public function serveAdminAsset(string $path): array
    {
        $assetPath = $this->adminDistPath . '/' . $path;
        
        // SPA fallback: serve index.html for non-existent paths (client-side routing)
        if (!file_exists($assetPath)) {
            return $this->serveAdmin();
        }
        
        // Security: prevent directory traversal for existing files
        $realBase = realpath($this->adminDistPath);
        $realAsset = realpath($assetPath);
        
        if ($realAsset === false || strpos($realAsset, $realBase) !== 0) {
            return ['status' => 404, 'error' => 'Not found'];
        }

        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
        ];

        $ext = pathinfo($assetPath, PATHINFO_EXTENSION);
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        
        header('Content-Type: ' . $contentType);
        readfile($assetPath);
        return ['raw' => true];
    }

    private function renderBuildInstructions(): array
    {
        header('Content-Type: text/html');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin UI - Build Required</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 3rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            text-align: center;
        }
        h1 { color: #111827; margin-bottom: 1rem; }
        p { color: #6b7280; margin-bottom: 1.5rem; line-height: 1.6; }
        code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.875rem;
        }
        pre {
            background: #1f2937;
            color: #e5e7eb;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: left;
            overflow-x: auto;
            margin: 1.5rem 0;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
        }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Admin UI Build Required</h1>
        <p>The Astro + Vue admin interface needs to be built before it can be used.</p>
        
        <h3>Build Instructions:</h3>
        <pre><code>cd admin-ui
npm install
npm run build</code></pre>
        
        <p>After building, refresh this page to see the new admin interface with:</p>
        <ul style="text-align: left; color: #6b7280;">
            <li>Modern Astro + Vue architecture</li>
            <li>D3.js data visualizations</li>
            <li>Responsive design</li>
            <li>Real-time statistics</li>
        </ul>
        
        <p style="margin-top: 2rem;">
            <a href="/api/info" class="btn">View API Status</a>
        </p>
    </div>
</body>
</html>';
        
        echo $html;
        return ['raw' => true];
    }
}
