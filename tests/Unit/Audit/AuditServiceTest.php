<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Audit;

use Click\Cms\Application\Audit\AuditService;
use Click\Cms\Domain\Audit\AuditAction;
use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Audit\JsonAuditLog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuditServiceTest extends TestCase
{
    private string $dir;
    private JsonAuditLog $log;
    private AuditService $service;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-auditsvc-' . bin2hex(random_bytes(6));
        $this->log = new JsonAuditLog($this->dir);
        $this->service = new AuditService($this->log);
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

    private function admin(): array
    {
        return ['username' => 'ada', 'role' => 'admin'];
    }

    private function record(string $actor, AuditAction $action, ContentKey $key, string $at): void
    {
        $this->log->append(AuditEntry::of($actor, $action, $key, new DateTimeImmutable($at)));
    }

    public function testRecentReturnsEntriesNewestFirstAsArrays(): void
    {
        $this->record('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00');
        $this->record('bob', AuditAction::Updated, ContentKey::page('home'), '2026-07-22T11:00:00+00:00');

        $result = $this->service->recent($this->admin(), 10);

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('bob', $result['entries'][0]['actor']);
        $this->assertSame('updated', $result['entries'][0]['action']);
        $this->assertSame('page:en:home', $result['entries'][0]['document']);
    }

    public function testForDocumentFiltersToOnePage(): void
    {
        $this->record('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00');
        $this->record('bob', AuditAction::Created, ContentKey::page('about'), '2026-07-22T10:30:00+00:00');

        $result = $this->service->forDocument(ContentKey::page('home'), $this->admin(), 10);

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['entries']);
        $this->assertSame('page:en:home', $result['entries'][0]['document']);
    }

    /**
     * The audit trail is an accountability record, and reading who did what is
     * an operator concern. A role without the run of the site's settings has no
     * business enumerating every editor's actions.
     */
    public function testViewingRequiresAnAdministrativeCapability(): void
    {
        $this->record('ada', AuditAction::Created, ContentKey::page('home'), '2026-07-22T10:00:00+00:00');

        $editor = ['username' => 'e', 'role' => 'editor'];

        $recent = $this->service->recent($editor, 10);
        $this->assertSame(403, $recent['status']);
        $this->assertNull($recent['entries']);
        $this->assertNotNull($recent['error']);

        $forDoc = $this->service->forDocument(ContentKey::page('home'), $editor, 10);
        $this->assertSame(403, $forDoc['status']);
    }

    /**
     * An unrecognised role falls to the least privileged, so it must not see
     * the trail either — the same fail-closed default the rest of the system
     * holds.
     */
    public function testAnUnknownRoleIsRefused(): void
    {
        $result = $this->service->recent(['username' => 'x', 'role' => 'wizard'], 10);

        $this->assertSame(403, $result['status']);
    }
}
