<?php

declare(strict_types=1);

namespace Click\Cms\Application\Config;

/**
 * Runtime settings an operator turns on and off from the admin UI.
 *
 * Deliberately separate from {@see CoreConfig}. That reads `config/core.json`,
 * which is baked into the image and cannot be changed from a running site —
 * right for bootstrap decisions like which storage backend to open, wrong for
 * anything an operator should be able to flip. These live in `data/`, the
 * writable directory that survives a redeploy, so a switch thrown here stays
 * thrown.
 *
 * The first such switch is headless mode: whether this instance renders its own
 * public website, or serves only the delivery API and leaves the front end to
 * someone else.
 *
 * A missing or corrupt file is not an error. Every setting has a default, so an
 * install that has never opened the settings screen — or whose settings file a
 * stray write damaged — runs on the defaults rather than failing to boot.
 */
final class Settings
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(
        private readonly string $path,
        array $values,
    ) {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        $values = [];

        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }

        return new self($path, $values);
    }

    /**
     * Whether this instance serves only the delivery API, with no rendered
     * public site of its own. Off by default: a fresh install renders its pages.
     */
    public function headless(): bool
    {
        return (bool) ($this->values['headless'] ?? false);
    }

    public function setHeadless(bool $on): void
    {
        $this->values['headless'] = $on;
        $this->persist();
    }

    /**
     * @return array{headless: bool}
     */
    public function toArray(): array
    {
        return ['headless' => $this->headless()];
    }

    /**
     * Write-then-rename, the same as content storage: a reader sees either the
     * old settings or the new ones, never a half-written file — which for a file
     * consulted on every public request would otherwise be a way to take the
     * site down mid-save.
     */
    private function persist(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode(
            $this->values,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
        }
    }
}
