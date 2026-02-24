<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_seo extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
    }

    public function getPluginId(): string
    {
        return 'seo';
    }

    public function getPluginName(): string
    {
        return 'SEO & Site Output';
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
            'GET /sitemap.xml' => [$this, 'serveSitemap'],
            'GET /robots.txt' => [$this, 'serveRobots'],
            'GET /api/seo/settings' => [$this, 'getSeoSettings'],
            'PUT /api/seo/settings' => [$this, 'updateSeoSettings'],
            'POST /api/seo/export' => [$this, 'exportStaticSite'],
        ];
    }

    public function hook_page_render(array $params): array
    {
        $page = $params['page'] ?? null;
        
        if (!$page) {
            return $params;
        }

        $seo = $this->getSeoData($page);
        
        return array_merge($params, [
            'seo' => $seo,
        ]);
    }

    public function hook_head(array $params): string
    {
        $page = $params['page'] ?? null;
        
        if (!$page) {
            return '';
        }

        $seo = $this->getSeoData($page);
        $config = $this->getSeoSettingsData();
        
        $tags = [];
        
        $tags[] = '<title>' . htmlspecialchars($seo['title'] ?? 'Untitled') . '</title>';
        
        if (!empty($seo['description'])) {
            $tags[] = '<meta name="description" content="' . htmlspecialchars($seo['description']) . '">';
        }
        
        $tags[] = '<meta property="og:title" content="' . htmlspecialchars($seo['title'] ?? 'Untitled') . '">';
        
        if (!empty($seo['description'])) {
            $tags[] = '<meta property="og:description" content="' . htmlspecialchars($seo['description']) . '">';
        }
        
        $ogImage = $seo['image'] ?? $config['ogImage'] ?? '';
        if (!empty($ogImage)) {
            $tags[] = '<meta property="og:image" content="' . htmlspecialchars($ogImage) . '">';
        }
        
        $tags[] = '<meta property="og:type" content="website">';
        
        $siteName = $config['siteName'] ?? 'Click CMS';
        $tags[] = '<meta property="og:site_name" content="' . htmlspecialchars($siteName) . '">';
        
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        
        if (!empty($seo['keywords'])) {
            $tags[] = '<meta name="keywords" content="' . htmlspecialchars($seo['keywords']) . '">';
        }

        if (!empty($seo['canonical'])) {
            $tags[] = '<link rel="canonical" href="' . htmlspecialchars($seo['canonical']) . '">';
        }
        
        return implode("\n", $tags);
    }

    private function getSeoData($page): array
    {
        $data = is_array($page) ? ($page['data'] ?? $page) : (is_object($page) ? ($page->data ?? []) : []);
        
        return [
            'title' => $data['title'] ?? $data['seo_title'] ?? 'Untitled',
            'description' => $data['seo_description'] ?? $data['description'] ?? '',
            'keywords' => $data['seo_keywords'] ?? '',
            'image' => $data['seo_image'] ?? '',
            'canonical' => $data['seo_canonical'] ?? '',
            'noindex' => $data['seo_noindex'] ?? false,
            'nofollow' => $data['seo_nofollow'] ?? false,
        ];
    }

    public function serveSitemap(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $pagesPath = $basePath . '/content/page';
        
        $pages = [];
        
        if (is_dir($pagesPath)) {
            $files = glob($pagesPath . '/*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                
                if (!$data) continue;
                
                $slug = $data['key'] ?? basename($file, '.json');
                
                if (isset($data['data']['seo_noindex']) && $data['data']['seo_noindex']) {
                    continue;
                }
                
                $pages[] = [
                    'loc' => $this->getBaseUrl() . '/' . $slug,
                    'lastmod' => $data['updated_at'] ?? $data['created_at'] ?? date('c'),
                    'changefreq' => $data['data']['sitemap_changefreq'] ?? 'weekly',
                    'priority' => $data['data']['sitemap_priority'] ?? 0.5,
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        
        foreach ($pages as $page) {
            $xml .= '<url>';
            $xml .= '<loc>' . htmlspecialchars($page['loc']) . '</loc>';
            $xml .= '<lastmod>' . htmlspecialchars($page['lastmod']) . '</lastmod>';
            $xml .= '<changefreq>' . htmlspecialchars($page['changefreq']) . '</changefreq>';
            $xml .= '<priority>' . htmlspecialchars((string)$page['priority']) . '</priority>';
            $xml .= '</url>';
        }
        
        $xml .= '</urlset>';
        
        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
        
        return ['raw' => true];
    }

    public function serveRobots(): array
    {
        $config = $this->getSeoSettingsData();
        
        $robots = "User-agent: *\n";
        
        if (!empty($config['disallow'])) {
            foreach ($config['disallow'] as $path) {
                $robots .= "Disallow: $path\n";
            }
        } else {
            $robots .= "Disallow: /admin\n";
            $robots .= "Disallow: /api\n";
        }
        
        if (!empty($config['allow'])) {
            foreach ($config['allow'] as $path) {
                $robots .= "Allow: $path\n";
            }
        }
        
        if (!empty($config['sitemap'])) {
            $robots .= "\nSitemap: " . $config['sitemap'] . "\n";
        } else {
            $robots .= "\nSitemap: " . $this->getBaseUrl() . "/sitemap.xml\n";
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        echo $robots;
        
        return ['raw' => true];
    }

    public function getSeoSettings(): array
    {
        return ['data' => $this->getSeoSettingsData()];
    }

    public function updateSeoSettings(): array
    {
        $data = $this->getJsonBody();
        
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/data/seo.json';
        
        $current = [];
        if (file_exists($configPath)) {
            $current = json_decode(file_get_contents($configPath), true) ?? [];
        }
        
        $updated = array_merge($current, $data);
        
        file_put_contents($configPath, json_encode($updated, JSON_PRETTY_PRINT));
        
        return ['data' => $updated];
    }

    public function exportStaticSite(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $pagesPath = $basePath . '/content/page';
        $exportPath = $basePath . '/export';
        
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0755, true);
        }
        
        $exported = [];
        
        if (is_dir($pagesPath)) {
            $files = glob($pagesPath . '/*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                
                if (!$data) continue;
                
                $slug = $data['key'] ?? basename($file, '.json');
                
                if (isset($data['data']['seo_noindex']) && $data['data']['seo_noindex']) {
                    continue;
                }
                
                $html = $this->generateStaticPage($slug, $data);
                
                $outputFile = $exportPath . '/' . $slug . '.html';
                file_put_contents($outputFile, $html);
                
                $exported[] = $slug;
            }
        }
        
        return ['data' => [
            'exported' => $exported,
            'count' => count($exported),
            'path' => $exportPath,
        ]];
    }

    private function generateStaticPage(string $slug, array $pageData): string
    {
        $config = $this->getSeoSettingsData();
        $seo = $this->getSeoData($pageData);
        
        $html = '<!DOCTYPE html>';
        $html .= '<html lang="en">';
        $html .= '<head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . htmlspecialchars($seo['title'] ?? 'Untitled') . '</title>';
        
        if (!empty($seo['description'])) {
            $html .= '<meta name="description" content="' . htmlspecialchars($seo['description']) . '">';
        }
        
        $html .= '<meta property="og:title" content="' . htmlspecialchars($seo['title'] ?? 'Untitled') . '">';
        $html .= '<meta property="og:type" content="website">';
        
        $html .= '</head>';
        $html .= '<body>';
        
        $content = $pageData['data']['content'] ?? $pageData['content'] ?? '';
        $html .= '<main>';
        $html .= $content;
        $html .= '</main>';
        
        $html .= '</body>';
        $html .= '</html>';
        
        return $html;
    }

    private function getSeoSettingsData(): array
    {
        $basePath = $this->pluginManager->getBasePath();
        $configPath = $basePath . '/data/seo.json';
        
        $defaults = [
            'siteName' => 'Click CMS',
            'ogImage' => '',
            'disallow' => ['/admin', '/api'],
            'allow' => [],
            'sitemap' => '',
        ];
        
        if (!file_exists($configPath)) {
            return $defaults;
        }
        
        $data = json_decode(file_get_contents($configPath), true);
        
        return array_merge($defaults, $data ?? []);
    }

    private function getBaseUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
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
