<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowTest extends TestCase
{
    private function metadataScript(string $workflow = 'release.yml', string $step = 'Work out what this release is'): string
    {
        $lines = file(dirname(__DIR__, 3) . '/.github/workflows/' . $workflow);
        $inStep = false;
        $inScript = false;
        $script = '';
        foreach ($lines as $line) {
            if (str_contains($line, '- name: ' . $step)) {
                $inStep = true;
                continue;
            }
            if ($inStep && trim($line) === 'run: |') {
                $inScript = true;
                continue;
            }
            if ($inScript) {
                if (trim($line) !== '' && !str_starts_with($line, '          ')) {
                    break;
                }
                $script .= substr($line, 10);
            }
        }
        self::assertNotSame('', $script);
        return $script;
    }

    public function testReleaseNotesAreDataRatherThanExecutableShell(): void
    {
        $dir = sys_get_temp_dir() . '/click-workflow-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $marker = $dir . '/executed';
        $output = $dir . '/output';
        $body = '$(printf injected > "$INJECTION_MARKER") [security]';
        // Model Actions' expression substitution before the shell sees run:.
        $script = str_replace('${{ github.event.release.body }}', $body, $this->metadataScript());
        try {
            $process = proc_open(['bash', '-eu', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, [
                'PATH' => getenv('PATH'), 'GITHUB_OUTPUT' => $output,
                'GITHUB_REF_NAME' => 'v1.2.3', 'RELEASE_BODY' => $body,
                'INJECTION_MARKER' => $marker,
            ]);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            self::assertFileDoesNotExist($marker, 'Release notes executed a shell substitution.');
            self::assertSame("version=1.2.3\nsecurity=true\n", file_get_contents($output));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($dir);
        }
    }

    public function testMalformedReleaseRefsAreRejectedBeforeProducingOutputs(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'click-release-output-');
        try {
            foreach (['main', 'v1.2.3;echo injected', 'v1.2.3$(echo injected)', 'v../escape'] as $ref) {
                file_put_contents($output, '');
                $process = proc_open(['bash', '-eu', '-c', $this->metadataScript()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
                    'PATH' => getenv('PATH'), 'GITHUB_OUTPUT' => $output,
                    'GITHUB_REF_NAME' => $ref, 'RELEASE_BODY' => '',
                ]);
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertNotSame(0, proc_close($process), $ref);
                self::assertSame('', file_get_contents($output), $ref);
            }
        } finally {
            unlink($output);
        }
    }
    public function testPagesRejectsReleaseContextButAcceptsIndependentTriggers(): void
    {
        $script = $this->metadataScript('pages.yml', 'Reject release-context publication');
        foreach (['push' => 0, 'schedule' => 0, 'workflow_dispatch' => 0, 'release' => 1, 'pull_request' => 1] as $event => $expected) {
            $process = proc_open(['bash', '-eu', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
                'PATH' => getenv('PATH'), 'PUBLISH_EVENT' => $event,
            ]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expected, proc_close($process), $event);
        }
    }

    public function testReleaseHasNoKnownBrokenReusablePagesFallback(): void
    {
        $root = dirname(__DIR__, 3);
        $release = file_get_contents($root . '/.github/workflows/release.yml');
        $pages = file_get_contents($root . '/.github/workflows/pages.yml');
        self::assertStringNotContainsString('uses: ./.github/workflows/pages.yml', $release);
        self::assertStringNotContainsString('workflow_call:', $pages);
        self::assertStringContainsString('exit 1', substr($release, strpos($release, 'PAGES_DISPATCH_TOKEN is required')));
        self::assertStringContainsString('.sha256 == $sha256', $release);
    }

}
