<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * What came back from one attempt, including the attempts that came back with
 * nothing.
 *
 * A connection refused and a 500 are both failures, and they are different
 * failures — one has an HTTP status and one does not — so the type has to be
 * able to say "no status" without that reading as zero.
 *
 * {@see $succeeded} is decided here rather than by the caller because "which
 * status codes count as delivered" is a rule, and a rule written at each call
 * site is a rule that differs between them.
 */
final class TransportResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?int $status,
        public readonly ?string $error,
    ) {}

    /**
     * A response arrived. Whether it counts as delivery is the 2xx question.
     *
     * 3xx is not success. The transport does not follow redirects — the URL was
     * checked against {@see WebhookUrlPolicy} before the request, and a redirect
     * would land somewhere that never was — so a 302 means the receiver is
     * pointing us somewhere we have not agreed to go, and the honest answer is
     * that the delivery did not happen.
     */
    public static function responded(int $status): self
    {
        if ($status >= 200 && $status < 300) {
            return new self(true, $status, null);
        }

        return new self(false, $status, "The endpoint answered {$status}.");
    }

    /** Nothing arrived: refused, timed out, TLS failed, DNS failed. */
    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
