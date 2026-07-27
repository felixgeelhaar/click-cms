<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Domain\Webhook\WebhookUrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * What a webhook is allowed to point at.
 *
 * A webhook endpoint is a URL the server fetches on a schedule, which makes it
 * a server-side request forgery primitive by construction. Only an
 * administrator can configure one, so this is defence in depth rather than the
 * only thing standing in the way — but "only an administrator" is exactly the
 * account worth phishing, and the payoff is reading cloud instance metadata or
 * knocking on services that trust the loopback interface.
 *
 * The rule is an allowlist of shapes, not a denylist of known-bad hosts: a
 * denylist has to enumerate every way of writing 127.0.0.1, and there are many.
 */
final class WebhookUrlPolicyTest extends TestCase
{
    private function policy(bool $allowPrivate = false, bool $allowHttp = false): WebhookUrlPolicy
    {
        return new WebhookUrlPolicy($allowPrivate, $allowHttp);
    }

    /* --------------------------------------------------------- accepted -- */

    public function testAnOrdinaryHttpsUrlIsAccepted(): void
    {
        $this->assertNull($this->policy()->refusalFor('https://example.com/hooks/click'));
    }

    public function testAPortAndAQueryAreFine(): void
    {
        $this->assertNull($this->policy()->refusalFor('https://example.com:8443/hooks?site=main'));
    }

    /* --------------------------------------------------------- schemes -- */

    /**
     * A webhook carries a signature and, often, the shape of unpublished work.
     * Plain HTTP puts both on the wire in clear, so it is opt-in per
     * installation rather than merely discouraged in a comment.
     */
    public function testPlainHttpIsRefusedUnlessAllowed(): void
    {
        $this->assertNotNull($this->policy()->refusalFor('http://example.com/hooks'));
        $this->assertNull($this->policy(allowHttp: true)->refusalFor('http://example.com/hooks'));
    }

    public function testAnythingThatIsNotHttpIsRefusedOutright(): void
    {
        foreach ([
            'file:///etc/passwd',
            'gopher://example.com/',
            'ftp://example.com/x',
            'php://filter/read=convert.base64-encode/resource=config.php',
            'data:text/plain,hello',
        ] as $url) {
            $this->assertNotNull(
                $this->policy(allowPrivate: true, allowHttp: true)->refusalFor($url),
                "{$url} should be refused whatever else is allowed"
            );
        }
    }

    /* ----------------------------------------------------- private space -- */

    /**
     * The cloud-metadata address, which is the single most valuable target for
     * this class of bug: it answers unauthenticated on many providers and hands
     * back credentials.
     */
    public function testTheCloudMetadataAddressIsRefused(): void
    {
        $this->assertNotNull($this->policy()->refusalFor('https://169.254.169.254/latest/meta-data/'));
    }

    public function testLoopbackIsRefused(): void
    {
        foreach ([
            'https://127.0.0.1/x',
            'https://127.1/x',
            'https://[::1]/x',
            'https://localhost/x',
        ] as $url) {
            $this->assertNotNull($this->policy()->refusalFor($url), "{$url} should be refused");
        }
    }

    public function testPrivateRangesAreRefused(): void
    {
        foreach ([
            'https://10.0.0.5/x',
            'https://192.168.1.1/x',
            'https://172.16.0.1/x',
            'https://[fd00::1]/x',
        ] as $url) {
            $this->assertNotNull($this->policy()->refusalFor($url), "{$url} should be refused");
        }
    }

    /**
     * A site whose front end really does live on the same private network — a
     * container talking to a sibling container — is a legitimate arrangement,
     * so this is configuration rather than a prohibition. It is off by default
     * because the safe answer has to be the one nobody has to choose.
     */
    public function testPrivateRangesAreAllowedWhenTheSiteSaysSo(): void
    {
        $this->assertNull($this->policy(allowPrivate: true)->refusalFor('https://10.0.0.5/hooks'));
        $this->assertNull($this->policy(allowPrivate: true)->refusalFor('https://localhost/hooks'));
    }

    /* ------------------------------------------------------- malformed -- */

    public function testSomethingThatIsNotAUrlIsRefused(): void
    {
        foreach (['', '   ', 'not a url', 'https://', '//example.com/x'] as $url) {
            $this->assertNotNull($this->policy()->refusalFor($url), "'{$url}' should be refused");
        }
    }

    /**
     * Credentials in the authority are refused: they would be written to the
     * endpoint list, shown in the admin, and copied into every log line that
     * mentions the endpoint.
     */
    public function testEmbeddedCredentialsAreRefused(): void
    {
        $this->assertNotNull($this->policy()->refusalFor('https://user:pass@example.com/hooks'));
    }

    /**
     * A refusal has to say what is wrong. An administrator reading "invalid
     * URL" against an address that looks fine to them goes and files a bug;
     * one reading "points inside this network" fixes it.
     */
    public function testARefusalNamesTheProblem(): void
    {
        $this->assertStringContainsString('private', strtolower((string) $this->policy()->refusalFor('https://10.0.0.5/x')));
        $this->assertStringContainsString('https', strtolower((string) $this->policy()->refusalFor('http://example.com/x')));
    }
}
