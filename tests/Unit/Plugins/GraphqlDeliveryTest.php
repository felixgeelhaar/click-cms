<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The GraphQL delivery plugin, exercised as itself.
 *
 * The version this replaced could return any account's password hash and could
 * write unvalidated content. These tests pin the boundary that makes it safe to
 * answer anonymously: it reads published pages, nothing else, and never writes.
 */
final class GraphqlDeliveryTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-graphql-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        );
        $this->content = new ContentService($storage);

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);

        require_once dirname(__DIR__, 3) . '/plugins/graphql/bootstrap.php';
        $this->plugin = new \Plugin_graphql_api($manager);

        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    private function seedPublished(string $slug, array $data): void
    {
        $this->content->save(Content::create(ContentKey::page($slug), $data));
        $storage = (new \ReflectionProperty($this->content, 'storage'))->getValue($this->content);
        $storage->publish(ContentKey::page($slug));
    }

    private function ask(string $query): array
    {
        $_GET['query'] = $query;
        return $this->plugin->handleGraphQL();
    }

    /* --------------------------------------------------------- the leak -- */

    public function testAnAccountQueryIsRefused(): void
    {
        // The seeded admin document exists on disk with a password hash; the old
        // plugin would hand it over. This one must not know the query at all.
        mkdir($this->base . '/content/user', 0o775, true);
        file_put_contents(
            $this->base . '/content/user/admin.json',
            json_encode(['id' => 'user:admin', 'data' => ['password' => 'a-secret-hash', 'role' => 'admin']])
        );

        $result = $this->ask('{ user(username: "admin") { password } }');

        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayNotHasKey('data', $result);
        $this->assertStringNotContainsString('a-secret-hash', json_encode($result));
    }

    public function testAListOfAccountsIsRefused(): void
    {
        $result = $this->ask('{ users { username } }');

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringNotContainsString('password', json_encode($result));
    }

    /**
     * Even inside a page query — which is allowed — a field outside the
     * allowlist must not come back, so a new internal field can never leak here.
     */
    public function testAnUnlistedFieldIsNotReturnedEvenOnAPage(): void
    {
        $this->seedPublished('home', ['title' => 'Home', 'password' => 'should-never-appear', 'sections' => []]);

        $result = $this->ask('{ page(slug: "home") { title password } }');

        $this->assertSame('Home', $result['data']['page']['title']);
        $this->assertArrayNotHasKey('password', $result['data']['page']);
        $this->assertStringNotContainsString('should-never-appear', json_encode($result));
    }

    /* ------------------------------------------------------ read-only -- */

    public function testAMutationIsRefused(): void
    {
        $result = $this->ask('mutation { createPage(input: { title: "Injected" }) { slug } }');

        $this->assertArrayHasKey('errors', $result);
        // And nothing was written.
        $this->assertNull($this->content->page('injected'));
        $this->assertSame([], $this->content->pages());
    }

    /* ------------------------------------------------ published only -- */

    public function testPageReadsThePublishedDocumentNotADraft(): void
    {
        // A working copy with no publish: delivery must not see it.
        $this->content->save(Content::create(ContentKey::page('secret'), ['title' => 'Unannounced', 'sections' => []]));

        $result = $this->ask('{ page(slug: "secret") { title } }');

        $this->assertNull($result['data']['page']);
    }

    public function testPublishedPagesAreReturned(): void
    {
        $this->seedPublished('home', ['title' => 'Home', 'sections' => []]);
        $this->seedPublished('about', ['title' => 'About', 'sections' => []]);

        $result = $this->ask('{ pages { slug title } }');

        $titles = array_column($result['data']['pages'], 'title');
        sort($titles);
        $this->assertSame(['About', 'Home'], $titles);
    }
}
