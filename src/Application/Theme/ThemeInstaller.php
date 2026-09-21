<?php

declare(strict_types=1);

namespace Click\Cms\Application\Theme;

use Click\Cms\Domain\Theme\Theme;

/**
 * Install a theme from an administrator-uploaded ZIP.
 *
 * Themes are CSS and assets only — no PHP — but the archive is still treated as
 * hostile until proven otherwise. Extraction mirrors the plugin marketplace:
 * every entry is validated against Zip Slip, written as a plain file (never a
 * live symlink), capped against a zip bomb, and moved into `themes/` with an
 * atomic same-filesystem rename once a valid `theme.json` is found.
 */
final class ThemeInstaller
{
    private const MAX_ENTRIES = 500;
    private const MAX_TOTAL_BYTES = 20 * 1024 * 1024;
    private const MANIFEST = 'theme.json';

    public function __construct(
        private readonly string $themesPath,
        private readonly string $workspacePath,
        private readonly ThemeRepository $themes,
    ) {
        if (!is_dir($this->workspacePath)) {
            mkdir($this->workspacePath, 0755, true);
        }
    }

    /**
     * Accept an administrator-supplied ZIP and install it under `themes/`.
     *
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     * @return array{success: bool, error?: string, theme?: array<string, mixed>}
     */
    public function upload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => $this->describeUploadError((int) ($file['error'] ?? -1))];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }

        $bytes = (int) ($file['size'] ?? filesize($tmp) ?: 0);
        if ($bytes <= 0) {
            return ['success' => false, 'error' => 'The file is empty'];
        }
        if ($bytes > self::MAX_TOTAL_BYTES) {
            return ['success' => false, 'error' => 'Archive is too large'];
        }

        $head = (string) @file_get_contents($tmp, false, null, 0, 4);
        if ($head === '' || !str_starts_with($head, 'PK')) {
            return ['success' => false, 'error' => 'File is not a ZIP archive'];
        }

        $uploadPath = $this->workspacePath . '/upload-' . bin2hex(random_bytes(8)) . '.zip';

        $moved = false;
        if (is_uploaded_file($tmp)) {
            $moved = move_uploaded_file($tmp, $uploadPath);
        } else {
            $moved = @copy($tmp, $uploadPath);
        }

        if (!$moved || !is_file($uploadPath)) {
            return ['success' => false, 'error' => 'Failed to move uploaded file'];
        }

        try {
            return $this->installFromZip($uploadPath);
        } finally {
            @unlink($uploadPath);
        }
    }

    /**
     * @return array{success: bool, error?: string, theme?: array<string, mixed>}
     */
    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file'];
        }

        $tempDir = $this->workspacePath . '/.extract-' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            $zip->close();

            return ['success' => false, 'error' => 'Could not create a working directory'];
        }

        $extractError = $this->safeExtract($zip, $tempDir);
        $zip->close();

        if ($extractError !== null) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => $extractError];
        }

        $manifestPath = $this->findManifest($tempDir);
        if ($manifestPath === null) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'No theme.json found in ZIP'];
        }

        $metadata = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($metadata)) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'Invalid theme.json'];
        }

        $themeDir = dirname($manifestPath);
        $themeId = $this->resolveId($metadata, basename($themeDir));
        $theme = Theme::fromArray($metadata, $themeId);
        if ($theme === null) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'theme.json does not describe an installable theme'];
        }

        $stylesheet = $themeDir . '/' . $theme->stylesheet();
        if (!is_file($stylesheet)) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'Theme stylesheet is missing from the archive'];
        }

        if (!is_dir($this->themesPath) && !mkdir($this->themesPath, 0755, true) && !is_dir($this->themesPath)) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'Could not create the themes directory'];
        }

        $targetDir = $this->themesPath . '/' . $theme->id;
        if (is_dir($targetDir)) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'Theme already exists'];
        }

        if (!@rename($themeDir, $targetDir)) {
            $this->cleanup($tempDir);

            return ['success' => false, 'error' => 'Failed to install the theme files'];
        }
        $this->cleanup($tempDir);

        // Confirm discovery sees it; a half-written install must not report success.
        $installed = $this->themes->find($theme->id);
        if ($installed === null) {
            return ['success' => false, 'error' => 'Theme installed but could not be read back'];
        }

        return [
            'success' => true,
            'theme' => $installed->toArray(),
        ];
    }

    private function resolveId(array $metadata, string $fallbackDir): string
    {
        $fromManifest = strtolower(trim((string) ($metadata['id'] ?? '')));
        if ($fromManifest !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/D', $fromManifest) === 1) {
            return $fromManifest;
        }

        $fromName = $this->slugify((string) ($metadata['name'] ?? ''));
        if ($fromName !== '') {
            return $fromName;
        }

        return $this->slugify($fallbackDir);
    }

    private function slugify(string $name): string
    {
        $id = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $id = (string) preg_replace('/-+/', '-', $id);

        return trim($id, '-');
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server allows',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has nowhere to store uploads',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload',
            UPLOAD_ERR_EXTENSION => 'An extension blocked the upload',
            default => 'The upload failed',
        };
    }

    private function safeExtract(\ZipArchive $zip, string $dest): ?string
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return 'Archive has too many entries';
        }

        $root = rtrim($dest, '/') . '/';
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return 'Unreadable archive entry';
            }

            $name = $stat['name'];

            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                || str_contains($name, "\0") || preg_match('#^[A-Za-z]:#', $name) === 1) {
                return 'Archive entry has an unsafe path';
            }
            $parts = explode('/', $name);
            if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
                return 'Archive entry has an unsafe path';
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_TOTAL_BYTES) {
                return 'Archive is too large when extracted';
            }

            $targetPath = $root . $name;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                return 'Could not create an extraction directory';
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                return 'Unreadable archive entry';
            }
            $bytes = stream_get_contents($stream);
            fclose($stream);
            if ($bytes === false || file_put_contents($targetPath, $bytes) === false) {
                return 'Failed to write an extracted file';
            }
            @chmod($targetPath, 0644);
        }

        return null;
    }

    private function findManifest(string $dir): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === self::MANIFEST) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
