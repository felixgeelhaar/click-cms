<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Webhook;

/**
 * Where a webhook may point.
 *
 * A webhook endpoint is an address this server fetches, unattended, on a
 * schedule. That is a server-side request forgery primitive by construction:
 * whoever chooses the URL chooses what the server connects to, from inside the
 * network, with whatever the network trusts it to reach.
 *
 * Only an administrator can configure one, so this is not the only thing
 * standing in the way. It is defence in depth, and worth having because the
 * administrator account is exactly the one worth phishing and the payoff is
 * large: `169.254.169.254` answers unauthenticated on several cloud providers
 * and hands back credentials, and plenty of internal services trust anything
 * arriving from the loopback interface.
 *
 * ## An allowlist of shapes, not a denylist of hosts
 *
 * Refusing "127.0.0.1" is close to useless, because `127.1`, `2130706433`,
 * `0x7f.1`, `[::ffff:127.0.0.1]` and a hostname resolving to any of them all
 * reach the same place. So the rule is expressed positively: the scheme must be
 * one of two, the host must resolve to an address that is not in reserved space,
 * and anything that cannot be established is refused rather than assumed
 * harmless.
 *
 * ## What this deliberately does not solve
 *
 * **DNS rebinding.** The name is resolved here, and resolved again by whatever
 * makes the request; a name that answers publicly now and privately then walks
 * past this check. Closing it properly means resolving once and connecting to
 * the pinned address, which needs control of the socket that neither the stream
 * wrapper nor a plain curl handle gives up cheaply. It is stated here rather
 * than papered over: the residual risk is an administrator configuring a
 * hostile hostname, which is a smaller set than "any URL at all".
 */
final class WebhookUrlPolicy
{
    /**
     * @param bool $allowPrivate Permit addresses inside this network. A front
     *        end in a sibling container is a legitimate arrangement, so this is
     *        configuration — off by default, because the safe answer must be
     *        the one nobody has to choose.
     * @param bool $allowHttp Permit plain HTTP. A delivery carries a signature
     *        and usually the shape of unpublished work, so cleartext is opt-in
     *        per installation rather than merely discouraged.
     */
    public function __construct(
        private readonly bool $allowPrivate = false,
        private readonly bool $allowHttp = false,
    ) {}

    /**
     * Why this URL may not be used, or null when it may.
     *
     * A reason rather than a boolean because an administrator reading "invalid
     * URL" against an address that looks fine to them files a bug, and one
     * reading "points inside this network" fixes the address.
     */
    public function refusalFor(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'A webhook needs a URL.';
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'That is not a URL this understands. It needs to look like https://example.com/hooks.';
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && $scheme !== 'http') {
            return 'A webhook must be an https address.';
        }

        if ($scheme === 'http' && !$this->allowHttp) {
            return 'A webhook must use https, so the delivery and its signature are not readable in transit. '
                . 'Plain http can be enabled per installation if the endpoint genuinely cannot offer https.';
        }

        // Credentials in the authority would be stored in the endpoint list,
        // displayed in the admin, and copied into every log line naming the
        // endpoint. A webhook authenticates with its signature instead.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Put no username or password in the URL. A webhook is authenticated by its signature.';
        }

        if ($this->allowPrivate) {
            return null;
        }

        return $this->refusalForHost($parts['host']);
    }

    /**
     * Whether the host lands somewhere this server should not be reaching.
     *
     * A bracketed IPv6 literal arrives from `parse_url` with its brackets, and
     * a name has to be resolved before anything can be said about it.
     */
    private function refusalForHost(string $host): ?string
    {
        $host = trim($host, '[]');

        $addresses = $this->addressesFor($host);

        if ($addresses === []) {
            // Refused rather than allowed. A name nothing can resolve is either
            // a typo — in which case saying so now is a kindness — or a name
            // that resolves somewhere this cannot see, which is not a reason to
            // trust it.
            return 'That host could not be resolved, so it cannot be checked. Check the address.';
        }

        foreach ($addresses as $address) {
            if (!$this->isPublic($address)) {
                return 'That address is inside this network (private, loopback or link-local), '
                    . 'and a webhook must point at a public address. '
                    . 'A site whose endpoint really is on the same network can allow this in its configuration.';
            }
        }

        return null;
    }

    /**
     * Every address the host stands for.
     *
     * A literal is itself. A name is resolved to both families, because a name
     * with a harmless A record and a loopback AAAA record is the obvious way
     * past a check that looks at only one.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $found = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $found = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $found[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Whether an address is in publicly routable space.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE` and `NO_RES_RANGE` between them cover RFC1918,
     * loopback, link-local (which is where the cloud metadata address lives),
     * carrier-grade NAT, IPv6 unique-local and the IPv4-mapped forms — which is
     * the whole point of using them rather than a hand-written list of prefixes
     * that would need to be right about all of those.
     */
    private function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
