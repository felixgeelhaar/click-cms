<?php

declare(strict_types=1);
namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\UpdateScheduler;
use Click\Cms\Application\Update\WebUpdateRunner;
use PHPUnit\Framework\TestCase;

final class WebUpdateRunnerTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-web-runner-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/config', 0755, true);
    }
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        putenv('CLICK_DEPLOYMENT_MANAGED');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->root);
    }
    private function configure(string $policy): void
    {
        file_put_contents($this->root . '/config/core.json', json_encode(['core'=>['updates'=>['policy'=>$policy,'feedUrl'=>'https://invalid.example/no-network','publicKey'=>'configured']]]));
    }
    public function testManualPolicyDoesNoNetworkOrSchedulerWork(): void
    {
        $this->configure('manual');
        $this->assertFalse(WebUpdateRunner::run($this->root));
        $this->assertDirectoryDoesNotExist($this->root . '/data');
    }
    public function testManagedDeploymentDoesNotRunWebUpdates(): void
    {
        $this->configure('minor'); putenv('CLICK_DEPLOYMENT_MANAGED=1');
        $this->assertFalse(WebUpdateRunner::run($this->root));
        $this->assertDirectoryDoesNotExist($this->root . '/data');
    }
    public function testPostRequestsNeverTriggerMaintenanceOrConsumeSchedule(): void
    {
        $this->configure('minor'); $_SERVER['REQUEST_METHOD']='POST';
        $this->assertFalse(WebUpdateRunner::run($this->root));
        $this->assertDirectoryDoesNotExist($this->root . '/data');
    }
    public function testRecentWebCheckSkipsNetwork(): void
    {
        $this->configure('minor');
        (new UpdateScheduler($this->root . '/data/updates/web'))->markChecked(time());
        $this->assertFalse(WebUpdateRunner::run($this->root));
        $this->assertFileDoesNotExist($this->root . '/data/updates/last-check.json');
    }
}
