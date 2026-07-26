<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\TrustedProxies;
use PHPUnit\Framework\TestCase;

/**
 * Which callers are allowed to describe the request on someone else's behalf.
 *
 * `X-Forwarded-*` headers are written by whoever sent the request, and a browser
 * can send any of them. They are only evidence when the sender is a proxy the
 * site actually put in front of itself, so this is the gate that decides that —
 * and it is deny-by-default: a site that has named no proxy trusts nobody.
 */
final class TrustedProxiesTest extends TestCase
{
    public function testASiteWithNoConfiguredProxiesTrustsNobody(): void
    {
        $proxies = new TrustedProxies([]);

        $this->assertFalse($proxies->trusts('10.0.0.1'));
        $this->assertFalse($proxies->trusts('127.0.0.1'));
    }

    public function testAnExactAddressIsTrusted(): void
    {
        $proxies = new TrustedProxies(['10.0.0.1', '::1']);

        $this->assertTrue($proxies->trusts('10.0.0.1'));
        $this->assertTrue($proxies->trusts('::1'));
        $this->assertFalse($proxies->trusts('10.0.0.2'));
    }

    /**
     * A range, because an ingress controller's pods do not keep one address —
     * naming a single IP would work until the pod was rescheduled.
     */
    public function testAnIpv4RangeIsTrusted(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/24']);

        $this->assertTrue($proxies->trusts('10.0.0.1'));
        $this->assertTrue($proxies->trusts('10.0.0.255'));
        $this->assertFalse($proxies->trusts('10.0.1.1'));
    }

    public function testAnIpv6RangeIsTrusted(): void
    {
        $proxies = new TrustedProxies(['2001:db8::/32']);

        $this->assertTrue($proxies->trusts('2001:db8::1'));
        $this->assertTrue($proxies->trusts('2001:db8:ffff::1'));
        $this->assertFalse($proxies->trusts('2001:db9::1'));
    }

    /** Families do not mix: an IPv4 address is not inside an IPv6 range. */
    public function testAnAddressOfTheWrongFamilyIsNotTrusted(): void
    {
        $this->assertFalse((new TrustedProxies(['2001:db8::/32']))->trusts('10.0.0.1'));
        $this->assertFalse((new TrustedProxies(['10.0.0.0/8']))->trusts('2001:db8::1'));
    }

    /**
     * Nonsense in the configuration must not open the gate. A prefix length that
     * is not a number, a range of /0 written by hand, an unparseable address —
     * all of them fail closed.
     */
    public function testMalformedConfigurationTrustsNobody(): void
    {
        $this->assertFalse((new TrustedProxies(['not-an-ip']))->trusts('10.0.0.1'));
        $this->assertFalse((new TrustedProxies(['10.0.0.0/abc']))->trusts('10.0.0.1'));
        $this->assertFalse((new TrustedProxies(['10.0.0.0/99']))->trusts('10.0.0.1'));
        $this->assertFalse((new TrustedProxies(['']))->trusts('10.0.0.1'));
    }

    public function testAnUnparseableRemoteAddressIsNotTrusted(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);

        $this->assertFalse($proxies->trusts(''));
        $this->assertFalse($proxies->trusts('nonsense'));
    }

    /**
     * A /0 would trust the entire internet, which is the same as switching the
     * check off — and if that is what somebody wants, it should not be something
     * they can do by accident with two characters.
     */
    public function testAZeroLengthRangeIsRefused(): void
    {
        $this->assertFalse((new TrustedProxies(['0.0.0.0/0']))->trusts('203.0.113.9'));
        $this->assertFalse((new TrustedProxies(['::/0']))->trusts('2001:db8::1'));
    }
}
