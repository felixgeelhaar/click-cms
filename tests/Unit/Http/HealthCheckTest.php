<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\HealthCheck;
use PHPUnit\Framework\TestCase;

/**
 * The container liveness and readiness probes.
 *
 * Extracted from the HTTP kernel so that "is this instance alive" and "is it
 * ready to serve" are one small thing that can be understood and tested on its
 * own, rather than two more methods on a class that already does everything.
 */
final class HealthCheckTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-health-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->base . '/content');
        @rmdir($this->base . '/data');
        @rmdir($this->base);
    }

    public function testLiveIsAlwaysAliveWithA200(): void
    {
        // Liveness answers "the process is running and can serve a request" — it
        // deliberately checks nothing else, so a readiness problem never makes
        // an orchestrator kill a process that is merely misconfigured.
        $result = (new HealthCheck($this->base, true))->live();

        $this->assertSame(200, $result['status']);
        $this->assertSame('alive', $result['data']['status']);
    }

    public function testReadyIs200WhenEverythingIsInPlace(): void
    {
        $result = (new HealthCheck($this->base, true))->ready();

        $this->assertSame(200, $result['status']);
        $this->assertSame('ready', $result['data']['status']);
        $this->assertTrue($result['data']['checks']['content_dir']);
        $this->assertTrue($result['data']['checks']['data_dir']);
        $this->assertTrue($result['data']['checks']['plugins_loaded']);
    }

    public function testReadyIs503WhenPluginsAreNotLoaded(): void
    {
        $result = (new HealthCheck($this->base, false))->ready();

        $this->assertSame(503, $result['status']);
        $this->assertSame('not_ready', $result['data']['status']);
        $this->assertFalse($result['data']['checks']['plugins_loaded']);
    }

    public function testReadyIs503WhenAWritableDirectoryIsMissing(): void
    {
        // A data directory that is not there or not writable means content cannot
        // be saved, so the instance is not ready to be sent traffic.
        $result = (new HealthCheck($this->base . '/nonexistent', true))->ready();

        $this->assertSame(503, $result['status']);
        $this->assertFalse($result['data']['checks']['data_dir']);
    }
}
