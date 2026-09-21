<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Audit\AuditService;
use Click\Cms\Domain\Audit\AuditAction;
use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\AuditController;
use Click\Cms\Infrastructure\Audit\JsonAuditLog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reading the audit trail through its HTTP controller.
 *
 * The service owns the permission check; the controller shapes a 403 as
 * {@see \Click\Cms\Http\ApiFault} and refuses non-GET methods.
 */
final class AuditControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-auditctl-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testGetReturnsEntriesForAnAdministrator(): void
    {
        $log = new JsonAuditLog($this->dir);
        $log->append(AuditEntry::of(
            'ada',
            AuditAction::Created,
            ContentKey::page('home'),
            new DateTimeImmutable('2026-07-22T10:00:00+00:00'),
        ));
        $controller = new AuditController(
            new AuditService($log),
            static fn (): ?array => ['username' => 'ada', 'role' => 'admin'],
        );

        $result = $controller->handle('GET');

        $this->assertCount(1, $result['data']);
        $this->assertSame('ada', $result['data'][0]['actor']);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testForbiddenIsShapedAsApiFault(): void
    {
        $controller = new AuditController(
            new AuditService(new JsonAuditLog($this->dir)),
            static fn (): ?array => ['username' => 'edd', 'role' => 'editor'],
        );

        $result = $controller->handle('GET');

        $this->assertSame(403, $result['status']);
        $this->assertSame('forbidden', $result['code']);
        $this->assertNotSame('', $result['error']);
    }

    public function testNonGetIsMethodNotAllowed(): void
    {
        $controller = new AuditController(
            new AuditService(new JsonAuditLog($this->dir)),
            static fn (): ?array => ['username' => 'ada', 'role' => 'admin'],
        );

        $result = $controller->handle('POST');

        $this->assertSame(405, $result['status']);
        $this->assertSame('method_not_allowed', $result['code']);
    }
}
