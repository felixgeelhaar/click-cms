<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * Which callers may describe a request on somebody else's behalf.
 *
 * `X-Forwarded-*` headers are written by whoever sent the request, and a browser
 * can send any header it likes. They are evidence only when the sender is a
 * proxy this site was actually put behind — otherwise a visitor could hand the
 * CMS a header and have it believe whatever they wrote. That matters here
 * because the forwarded prefix ends up in every URL the site emits, so an
 * attacker who could set it would rewrite every link on a page, and a cached
 * render would then serve their version to everyone else.
 *
 * Deny by default: a site that has named no proxy trusts nobody, which is the
 * behaviour of every installation that has never heard of this setting.
 */
final class TrustedProxies
{
    /** @param list<string> $ranges Addresses or CIDR ranges. */
    public function __construct(private readonly array $ranges) {}

    public function trusts(string $remoteAddress): bool
    {
        $address = @inet_pton($remoteAddress);
        if ($address === false) {
            return false;
        }

        foreach ($this->ranges as $range) {
            if ($this->matches($address, (string) $range)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $address, string $range): bool
    {
        [$subnet, $bits] = $this->split($range);
        if ($subnet === null) {
            return false;
        }

        // Mixing families is never a match: an IPv4 address is not inside an
        // IPv6 range, and inet_pton makes that visible as a length difference.
        if (strlen($subnet) !== strlen($address)) {
            return false;
        }

        // A whole address, so nothing to mask.
        if ($bits === null) {
            return hash_equals($subnet, $address);
        }

        $maxBits = strlen($subnet) * 8;
        // /0 would trust the entire internet — the same as switching the check
        // off, which is not something anyone should be able to do by accident
        // with two characters. Refused rather than honoured.
        if ($bits <= 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && !hash_equals(substr($subnet, 0, $wholeBytes), substr($address, 0, $wholeBytes))) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        // The partial byte: compare only the leading bits the prefix covers.
        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($subnet[$wholeBytes]) & $mask) === (ord($address[$wholeBytes]) & $mask);
    }

    /**
     * Split `10.0.0.0/24` into its packed subnet and prefix length.
     *
     * Anything unparseable comes back as a null subnet and therefore matches
     * nothing: a typo in the configuration must fail closed, because the
     * alternative is a site that silently trusts more than its operator wrote.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function split(string $range): array
    {
        $range = trim($range);
        if ($range === '') {
            return [null, null];
        }

        $slash = strrpos($range, '/');
        if ($slash === false) {
            $packed = @inet_pton($range);

            return [$packed === false ? null : $packed, null];
        }

        $subnet = substr($range, 0, $slash);
        $bits = substr($range, $slash + 1);
        $packed = @inet_pton($subnet);

        if ($packed === false || $bits === '' || !ctype_digit($bits)) {
            return [null, null];
        }

        return [$packed, (int) $bits];
    }
}
