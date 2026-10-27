<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;

final class FormsGuardAndNotificationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/plugins/forms/SubmissionGuard.php';
        require_once dirname(__DIR__, 3) . '/plugins/forms/Notification.php';
        $this->root = sys_get_temp_dir() . '/click-form-guards-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testWindowExpiresAndNoRawIpIsStored(): void
    {
        $guard = new \FormsSubmissionGuard($this->root);
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($guard->allow('192.0.2.7', 1200));
        }
        self::assertFalse($guard->allow('192.0.2.7', 1200));
        self::assertTrue($guard->allow('192.0.2.7', 1800));
        self::assertStringNotContainsString('192.0.2.7', file_get_contents($this->root . '/limits.json'));
    }

    public function testSiteLimitBoundsDistributedTrafficAndStateSize(): void
    {
        $guard = new \FormsSubmissionGuard($this->root);
        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($guard->allow('192.0.2.' . $i, 1200));
        }
        self::assertFalse($guard->allow('203.0.113.1', 1200));
        self::assertLessThan(12000, filesize($this->root . '/limits.json'));
    }

    public function testAlertUsesOnlyOperatorHeadersAndAReference(): void
    {
        $config = $this->root . '/forms.json';
        file_put_contents($config, json_encode(['notifications' => [
            'enabled' => true, 'to' => 'editor@example.invalid', 'from' => 'cms@example.invalid', 'locale' => 'de',
        ]]));
        $notification = new class($config) extends \FormsNotification {
            public array $captured = [];
            protected function deliver(string $to, string $subject, string $body, array $headers): bool
            {
                $this->captured = compact('to', 'subject', 'body', 'headers');
                return true;
            }
        };
        self::assertSame('submitted', $notification->send('reference-123'));
        self::assertSame('editor@example.invalid', $notification->captured['to']);
        self::assertSame('cms@example.invalid', $notification->captured['headers']['From']);
        self::assertStringContainsString('Referenz: reference-123', $notification->captured['body']);
        self::assertStringNotContainsString('Reply-To', json_encode($notification->captured));
    }

    public function testTransportFailureIsReported(): void
    {
        $config = $this->root . '/forms.json';
        file_put_contents($config, json_encode(['notifications' => [
            'enabled' => true, 'to' => 'editor@example.invalid', 'from' => 'cms@example.invalid',
        ]]));
        $notification = new class($config) extends \FormsNotification {
            protected function deliver(string $to, string $subject, string $body, array $headers): bool
            {
                throw new \RuntimeException('MTA unavailable');
            }
        };
        self::assertSame('failed', $notification->send('reference-123'));
    }
}
