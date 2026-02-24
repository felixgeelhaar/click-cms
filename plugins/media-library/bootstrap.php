<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

class Plugin_media_library extends \Click\Cms\Application\Plugin\BasePlugin
{
    private string $mediaPath;
    private string $uploadsPath;
    private string $thumbnailsPath;

    public function __construct($pluginManager)
    {
        parent::__construct($pluginManager);
        $basePath = $this->pluginManager->getBasePath();
        $this->mediaPath = $basePath . '/content/media';
        $this->uploadsPath = $this->mediaPath . '/uploads';
        $this->thumbnailsPath = $this->mediaPath . '/thumbnails';
        
        $this->ensureDirectoriesExist();
    }

    private function ensureDirectoriesExist(): void
    {
        foreach ([$this->mediaPath, $this->uploadsPath, $this->thumbnailsPath] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function getPluginId(): string
    {
        return 'media-library';
    }

    public function getPluginName(): string
    {
        return 'Media Library';
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
            'GET /api/media-library' => [$this, 'listMedia'],
            'POST /api/media-library/upload' => [$this, 'uploadMedia'],
            'DELETE /api/media-library/:filename' => [$this, 'deleteMedia'],
            'GET /media/uploads/:filename' => [$this, 'serveMedia'],
            'GET /media/thumbnails/:filename' => [$this, 'serveThumbnail'],
        ];
    }

    public function listMedia(): array
    {
        $files = [];
        
        if (!is_dir($this->uploadsPath)) {
            return ['data' => []];
        }

        $items = scandir($this->uploadsPath);
        if ($items === false) {
            return ['data' => []];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $filePath = $this->uploadsPath . '/' . $item;
            if (!is_file($filePath)) continue;

            $fileInfo = $this->getFileInfo($item, $filePath);
            if ($fileInfo) {
                $files[] = $fileInfo;
            }
        }

        usort($files, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return ['data' => $files];
    }

    public function uploadMedia(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['error' => 'Method not allowed', 'status' => 405];
        }

        if (!isset($_FILES['file'])) {
            return ['error' => 'No file uploaded', 'status' => 400];
        }

        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload error: ' . $file['error'], 'status' => 400];
        }

        $filename = $this->sanitizeFilename($file['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'mp4', 'webm', 'mp3', 'wav', 'zip'];
        if (!in_array($ext, $allowedExtensions)) {
            return ['error' => 'File type not allowed', 'status' => 400];
        }

        $filename = $this->ensureUniqueFilename($filename);
        $destination = $this->uploadsPath . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['error' => 'Failed to save file', 'status' => 500];
        }

        $metadata = [
            'original_name' => $file['name'],
            'filename' => $filename,
            'size' => filesize($destination),
            'mime_type' => $this->getMimeType($destination),
            'dimensions' => null,
            'alt' => '',
            'caption' => '',
            'created_at' => date('c'),
        ];

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $dimensions = $this->getImageDimensions($destination);
            if ($dimensions) {
                $metadata['dimensions'] = $dimensions;
            }
            
            $this->createThumbnail($destination, $filename, $ext);
        }

        $metadataPath = $this->mediaPath . '/' . $filename . '.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));

        return [
            'data' => $metadata,
            'status' => 201
        ];
    }

    public function deleteMedia(string $filename): array
    {
        $filename = $this->sanitizeFilename($filename);
        
        $filePath = $this->uploadsPath . '/' . $filename;
        $thumbPath = $this->thumbnailsPath . '/' . $filename;
        $metadataPath = $this->mediaPath . '/' . $filename . '.json';

        $deleted = false;

        if (file_exists($filePath)) {
            unlink($filePath);
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

    public function serveMedia(string $filename): array
    {
        $filename = $this->sanitizeFilename($filename);
        $filePath = $this->uploadsPath . '/' . $filename;

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
        $filename = $this->sanitizeFilename($filename);
        $thumbPath = $this->thumbnailsPath . '/' . $filename;
        
        $originalPath = $this->uploadsPath . '/' . $filename;
        
        if (!file_exists($thumbPath) && file_exists($originalPath)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $this->createThumbnail($originalPath, $filename, $ext);
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

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }

    private function ensureUniqueFilename(string $filename): string
    {
        if (!file_exists($this->uploadsPath . '/' . $filename)) {
            return $filename;
        }

        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $counter = 1;

        while (file_exists($this->uploadsPath . '/' . $filename)) {
            $filename = $baseName . '_' . $counter . '.' . $ext;
            $counter++;
        }

        return $filename;
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

    private function getImageDimensions(string $filePath): ?array
    {
        if (!function_exists('getimagesize')) {
            return null;
        }

        $dimensions = @getimagesize($filePath);
        
        if ($dimensions === false) {
            return null;
        }

        return [
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];
    }

    private function createThumbnail(string $sourcePath, string $filename, string $ext): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return false;
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

        $thumbPath = $this->thumbnailsPath . '/' . $filename;
        
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

    private function getFileInfo(string $filename, string $filePath): ?array
    {
        $metadataPath = $this->mediaPath . '/' . $filename . '.json';
        
        if (file_exists($metadataPath)) {
            $metadata = json_decode(file_get_contents($metadataPath), true);
            if (is_array($metadata)) {
                $metadata['url'] = '/media/uploads/' . $filename;
                $metadata['thumbnail_url'] = '/media/thumbnails/' . $filename;
                return $metadata;
            }
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return [
            'filename' => $filename,
            'original_name' => $filename,
            'size' => filesize($filePath),
            'mime_type' => $this->getMimeType($filePath),
            'dimensions' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) 
                ? $this->getImageDimensions($filePath) 
                : null,
            'alt' => '',
            'caption' => '',
            'created_at' => date('c', filemtime($filePath)),
            'url' => '/media/uploads/' . $filename,
            'thumbnail_url' => '/media/thumbnails/' . $filename,
        ];
    }

    public function updateMediaMetadata(string $filename, array $data): array
    {
        $filename = $this->sanitizeFilename($filename);
        $metadataPath = $this->mediaPath . '/' . $filename . '.json';

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

        return ['data' => $metadata];
    }
}
