<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Http\RedirectsController;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * Managing the redirect rule set.
 *
 * The rules are read by the kernel on every miss, so what this controller stores
 * must always be safe to trust without re-checking — which is why a hostile
 * entry is dropped on the way in rather than stored and re-validated later.
 */
final class RedirectsControllerTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private RedirectsController $redirects;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-redirects-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        $_POST = [];

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->redirects = new RedirectsController($this->content);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    private function save(array $redirects): array
    {
        $_POST = ['redirects' => $redirects];
        $r = $this->redirects->replace();
        $_POST = [];
        return $r;
    }

    public function testRulesRoundTripThroughSaveAndLoad(): void
    {
        $this->save([
            ['from' => '/old', 'to' => '/new', 'permanent' => true],
            ['from' => '/legacy', 'to' => 'https://example.com', 'permanent' => false],
        ]);

        $listed = $this->redirects->list()['data'];
        $this->assertCount(2, $listed);
        $this->assertSame('/new', $listed[0]['to']);

        // And the rules the kernel would consult match.
        $this->assertSame('/new', $this->redirects->rules()->match('/old')?->to);
    }

    public function testAHostileRuleIsDroppedOnTheWayIn(): void
    {
        $result = $this->save([
            ['from' => '/x', 'to' => 'javascript:alert(1)'],
            ['from' => '/y', 'to' => '/fine'],
        ]);

        // Only the safe rule survived, and that is what is on disk.
        $this->assertCount(1, $result['data']);
        $this->assertSame('/fine', $result['data'][0]['to']);
        $this->assertNull($this->redirects->rules()->match('/x'));
    }

    public function testAnEmptyListClearsTheRules(): void
    {
        $this->save([['from' => '/old', 'to' => '/new']]);
        $this->save([]);

        $this->assertSame(0, $this->redirects->rules()->count());
    }
}
