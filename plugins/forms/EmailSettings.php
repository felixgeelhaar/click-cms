<?php

declare(strict_types=1);

/** Site-local, deployment-safe alert preferences. Legacy private config remains readable. */
final class FormsEmailSettings
{
    public function __construct(private readonly string $path, private readonly string $legacyPath) {}

    public function read(): array
    {
        $path = is_file($this->path) ? $this->path : $this->legacyPath;
        $config = is_file($path) ? json_decode((string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
        if (!is_array($config) || (isset($config['notifications']) && !is_array($config['notifications']))) {
            throw new RuntimeException('Invalid email settings');
        }
        $value = $config['notifications'] ?? [];
        return [
            'enabled' => ($value['enabled'] ?? false) === true,
            'to' => is_string($value['to'] ?? null) ? $value['to'] : '',
            'from' => is_string($value['from'] ?? null) ? $value['from'] : '',
            'locale' => ($value['locale'] ?? 'en') === 'de' ? 'de' : 'en',
        ];
    }

    public function save(array $value): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot save email settings');
        }
        $temp = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $json = json_encode(['notifications' => $value], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@chmod($temp, 0600) || !@rename($temp, $this->path)) {
                throw new RuntimeException('Cannot save email settings');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
