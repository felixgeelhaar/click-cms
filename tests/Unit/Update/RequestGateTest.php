<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\RequestGate;
use PHPUnit\Framework\TestCase;

final class RequestGateTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-gate-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/updates', 0755, true);
    }
    protected function tearDown(): void
    {
        @unlink($this->root . '/data/updates/requests.lock');
        @rmdir($this->root . '/data/updates'); @rmdir($this->root . '/data'); @rmdir($this->root);
    }
    public function testActiveReaderPreventsCodeMutation(): void
    {
        $reader = fopen($this->root . '/data/updates/requests.lock', 'c');
        flock($reader, LOCK_SH);
        try {
            $called = false;
            $this->assertNull(RequestGate::write($this->root, static function () use (&$called): void { $called = true; }));
            $this->assertFalse($called);
        } finally { fclose($reader); }
        $this->assertSame('installed', RequestGate::write($this->root, static fn () => 'installed'));
    }
    public function testWriterExcludesReadersAndAlwaysReleasesAfterException(): void
    {
        try {
            RequestGate::write($this->root, function (): void {
                $reader = fopen($this->root . '/data/updates/requests.lock', 'c');
                $this->assertFalse(flock($reader, LOCK_SH | LOCK_NB));
                fclose($reader);
                throw new \RuntimeException('injected');
            });
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $error) { $this->assertSame('injected', $error->getMessage()); }
        $this->assertSame(true, RequestGate::write($this->root, static fn () => true));
    }
    public function testOwnRequestLockCanUpgradeWithoutDeadlocking(): void
    {
        $reader = fopen($this->root . '/data/updates/requests.lock', 'c');
        flock($reader, LOCK_SH);
        RequestGate::adopt($this->root, $reader);
        try {
            $this->assertSame('installed', RequestGate::write($this->root, static fn () => 'installed'));
            $this->assertTrue(RequestGate::attempted(), 'Old process must exit rather than boot replaced code');
        } finally { fclose($reader); }
    }
}
