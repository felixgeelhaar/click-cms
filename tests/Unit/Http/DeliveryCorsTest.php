<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Http\ApiGuard;
use Click\Cms\Http\DeliveryCors;
use PHPUnit\Framework\TestCase;

/**
 * The cross-origin decision for the delivery API.
 *
 * Getting this wrong is dangerous quietly — an over-open origin lets any page on
 * it read a site's content — so each rule is pinned directly rather than only
 * through a full request.
 */
final class DeliveryCorsTest extends TestCase
{
    private string $sessionsDir;

    protected function setUp(): void
    {
        $this->sessionsDir = sys_get_temp_dir() . '/click-cms-cors-' . bin2hex(random_bytes(6));
        mkdir($this->sessionsDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->sessionsDir);
    }

    private function cors(array $allowed): DeliveryCors
    {
        return new DeliveryCors($allowed, new ApiGuard(new SessionStore($this->sessionsDir, 1800)));
    }

    public function testNoOriginHeaderMeansCorsDoesNotApply(): void
    {
        $this->assertNull($this->cors(['https://front.example'])->evaluate('pages', 'GET', []));
    }

    public function testAnUnlistedOriginIsNotAnswered(): void
    {
        $decision = $this->cors(['https://front.example'])
            ->evaluate('pages', 'GET', ['HTTP_ORIGIN' => 'https://evil.example']);

        $this->assertNull($decision);
    }

    public function testAListedOriginReadingPublishedContentIsAnswered(): void
    {
        $decision = $this->cors(['https://front.example'])
            ->evaluate('pages', 'GET', ['HTTP_ORIGIN' => 'https://front.example']);

        $this->assertNotNull($decision);
        $this->assertSame('https://front.example', $decision['headers']['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $decision['headers']['Vary']);
        // Never credentialed: no allow-credentials header at all.
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $decision['headers']);
        $this->assertNull($decision['preflight']);
    }

    public function testALIstedOriginToANonPublicPathIsNotAnswered(): void
    {
        // The origin is allowed, but a management path is not public, so CORS
        // must not open it — that is what stops CORS widening what is reachable.
        $decision = $this->cors(['https://front.example'])
            ->evaluate('users', 'GET', ['HTTP_ORIGIN' => 'https://front.example']);

        $this->assertNull($decision);
    }

    public function testAPreflightIsAnsweredForTheMethodItAnnounces(): void
    {
        $decision = $this->cors(['https://front.example'])->evaluate('pages', 'OPTIONS', [
            'HTTP_ORIGIN' => 'https://front.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNotNull($decision);
        $this->assertSame(204, $decision['preflight']['status']);
    }

    public function testAPreflightForAWriteIsNotAnswered(): void
    {
        // A preflight announcing POST is asking to write cross-origin; the
        // delivery API is read-only public, so it is refused.
        $decision = $this->cors(['https://front.example'])->evaluate('pages', 'OPTIONS', [
            'HTTP_ORIGIN' => 'https://front.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($decision);
    }

    public function testNoOriginsConfiguredMeansSameOriginOnly(): void
    {
        $this->assertNull(
            $this->cors([])->evaluate('pages', 'GET', ['HTTP_ORIGIN' => 'https://front.example'])
        );
    }
}
