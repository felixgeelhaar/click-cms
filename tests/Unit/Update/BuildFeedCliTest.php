<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;

final class BuildFeedCliTest extends TestCase
{
    private string $root;
    private string $privateKey;
    private string $publicKey;
    private string $releases;
    private string $output;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-build-feed-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $private));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->privateKey = $this->root . '/private.pem';
        $this->publicKey = $details['key'];
        file_put_contents($this->privateKey, $private);
        $this->releases = $this->root . '/releases.json';
        file_put_contents($this->releases, json_encode([[
            'version' => '1.9.0',
            'packageUrl' => 'https://example.test/click-cms-1.9.0.zip',
            'sha256' => str_repeat('a', 64),
            'security' => false,
        ]], JSON_THROW_ON_ERROR));
        $this->output = $this->root . '/out';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTrailingSequenceAndTtlFlagsAreAppliedToTheSignedFeed(): void
    {
        [$status, $stdout, $stderr] = $this->runFeed('--sequence=731', '--ttl-days=7');
        self::assertSame(0, $status, $stdout . $stderr);

        $body = file_get_contents($this->output . '/feed.json');
        self::assertNotFalse($body);
        $feed = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(731, $feed['sequence']);
        self::assertSame('1.9.0', $feed['releases'][0]['version']);
        $secondsUntilExpiry = strtotime($feed['expires']) - time();
        self::assertGreaterThanOrEqual(7 * 86400 - 2, $secondsUntilExpiry);
        self::assertLessThanOrEqual(7 * 86400 + 2, $secondsUntilExpiry);

        $signature = base64_decode(trim((string) file_get_contents($this->output . '/feed.json.sig')), true);
        self::assertNotFalse($signature);
        self::assertSame(1, openssl_verify($body, $signature, $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function testMalformedOrRepeatedOptionsFailInsteadOfSilentlyFallingBack(): void
    {
        [$status, , $stderr] = $this->runFeed('--sequence=not-a-number');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('positive integer', $stderr);
        self::assertFileDoesNotExist($this->output . '/feed.json');

        [$status, , $stderr] = $this->runFeed('--sequence=12', '--sequence=13');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('only be supplied once', $stderr);
        self::assertFileDoesNotExist($this->output . '/feed.json');
    }

    /** @return array{int, string, string} */
    private function runFeed(string ...$options): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/updates/build-feed.php', $this->releases, $this->privateKey, $this->output, ...$options];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
