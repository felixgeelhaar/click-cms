<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Webhook;

use Click\Cms\Infrastructure\Webhook\CurlTransport;
use Click\Cms\Infrastructure\Webhook\StreamTransport;
use PHPUnit\Framework\TestCase;

final class TransportResponseLimitTest extends TestCase
{
    public function testTransportsRejectOversizedResponses(): void
    {
        // A real HTTP response exercises buffering, not a mock of transport options.
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            $this->markTestSkipped('Loopback sockets unavailable: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $router = tempnam(sys_get_temp_dir(), 'click-webhook-response-');
        file_put_contents($router, '<?php echo str_repeat("x", isset($_GET["large"]) ? 1048576 : 2);');
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        try {
            $ready = false;
            for ($i = 0; $i < 100; $i++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($probe !== false) { fclose($probe); $ready = true; break; }
                usleep(10000);
            }
            $this->assertTrue($ready, 'HTTP fixture must start');
            $transports = [new StreamTransport()];
            if (extension_loaded('curl')) { $transports[] = new CurlTransport(); }
            foreach ($transports as $transport) {
                $this->assertTrue($transport->post('http://' . $address, '{}', [], 2)->succeeded);
                $result = $transport->post('http://' . $address . '/?large=1', '{}', [], 2);
                $this->assertFalse($result->succeeded, get_class($transport));
                $this->assertStringContainsString('limit', $result->error ?? '');
            }
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            unlink($router);
        }
    }
}
