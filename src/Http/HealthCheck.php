<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * The container liveness and readiness probes.
 *
 * Two questions an orchestrator asks, kept deliberately apart:
 *
 *  - **Live** — is the process running and able to answer at all? It checks
 *    nothing else on purpose. A liveness probe that failed on a configuration
 *    problem would have the orchestrator restart a process that a restart cannot
 *    fix, turning a misconfiguration into a crash loop.
 *  - **Ready** — is it safe to send this instance traffic? Content and data must
 *    be writable and the plugins must have loaded, because an instance that
 *    cannot save content should be taken out of rotation, not handed requests.
 *
 * Pulled out of the HTTP kernel so it is one small testable thing rather than
 * two more methods on a class that already holds too much.
 */
final class HealthCheck
{
    public function __construct(
        private readonly string $basePath,
        private readonly bool $pluginsLoaded,
    ) {}

    /**
     * @return array{status: int, data: array{status: string, timestamp: int}}
     */
    public function live(): array
    {
        return [
            'status' => 200,
            'data' => [
                'status' => 'alive',
                'timestamp' => time(),
            ],
        ];
    }

    /**
     * @return array{status: int, data: array{status: string, timestamp: int, checks: array<string, bool>}}
     */
    public function ready(): array
    {
        $content = $this->basePath . '/content';
        $data = $this->basePath . '/data';

        $checks = [
            'content_dir' => is_dir($content) && is_writable($content),
            'data_dir' => is_dir($data) && is_writable($data),
            'plugins_loaded' => $this->pluginsLoaded,
        ];

        $ready = !in_array(false, $checks, true);

        return [
            'status' => $ready ? 200 : 503,
            'data' => [
                'status' => $ready ? 'ready' : 'not_ready',
                'timestamp' => time(),
                'checks' => $checks,
            ],
        ];
    }
}
