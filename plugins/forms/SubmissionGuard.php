<?php

declare(strict_types=1);

/** A bounded, site-scoped fixed-window limiter. Never trusts forwarded IP headers. */
final class FormsSubmissionGuard
{
    public function __construct(private readonly string $directory) {}

    public function allow(string $address, ?int $now = null): bool
    {
        $now ??= time();
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Form rate storage is unavailable');
        }
        $path = $this->directory . '/limits.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Form rate storage is unavailable');
        }
        @chmod($path, 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock form rate storage');
            }
            $raw = stream_get_contents($handle, 1048577);
            if ($raw === false || strlen($raw) > 1048576) {
                throw new RuntimeException('Invalid form rate storage');
            }
            $state = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new RuntimeException('Invalid form rate storage');
            }
            $state['salt'] ??= bin2hex(random_bytes(32));
            $window = intdiv($now, 600);
            if (($state['window'] ?? null) !== $window) {
                $state = ['salt' => $state['salt'], 'window' => $window, 'total' => 0, 'clients' => []];
            }
            $key = hash_hmac('sha256', $address, $state['salt']);
            $allowed = ($state['total'] ?? 0) < 100 && ($state['clients'][$key] ?? 0) < 5;
            if ($allowed) {
                $state['total']++;
                $state['clients'][$key] = ($state['clients'][$key] ?? 0) + 1;
            }
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)) {
                throw new RuntimeException('Cannot save form rate storage');
            }
            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
