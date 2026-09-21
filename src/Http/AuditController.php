<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Audit\AuditService;

/**
 * The audit trail — who did what, across the whole site.
 *
 * An operator accountability tool. The service gates reading to administrators;
 * this controller only needs a session (enforced by the kernel) and hands the
 * user to the service, which decides. A 403 from the service is shaped with
 * {@see ApiFault} so clients that read `code` get a stable machine string.
 *
 * Pulled out of the kernel because listing the trail is not the job of the
 * thing that turns requests into responses. Application only routes the path.
 */
final class AuditController
{
    /**
     * @param callable(): (?array<string, mixed>) $currentUser Resolves the
     *        signed-in user for the current request, or null when anonymous.
     */
    public function __construct(
        private readonly ?AuditService $audit,
        private readonly mixed $currentUser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $method): array
    {
        if ($method !== 'GET') {
            return ApiFault::of(405, 'Method not allowed', 'method_not_allowed');
        }

        $user = ($this->currentUser)() ?? [];
        $result = $this->audit?->recent($user, 100)
            ?? ['entries' => null, 'error' => 'Audit is unavailable.', 'status' => 500];

        if ($result['error'] !== null) {
            $status = (int) ($result['status'] ?? 500);
            if ($status === 403) {
                return ApiFault::of(403, (string) $result['error'], 'forbidden');
            }

            return ['status' => $status, 'error' => $result['error']];
        }

        return ['data' => $result['entries']];
    }
}
