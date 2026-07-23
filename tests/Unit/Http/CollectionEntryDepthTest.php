<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Collection\BackReferenceService;
use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\History\HistoryService;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Http\CollectionsController;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The collection entry editor is meant to be as capable as the page editor:
 * version history it can restore from, and a view of which languages the entry
 * exists in. Both ride machinery a page already has; these tests prove an entry
 * reaches it through the collections controller.
 *
 * The controller reads its request body through {@see CollectionsController} —
 * which falls back to `$_POST` when `php://input` is empty — and its locale from
 * `$_GET`, so both superglobals are the seam the test drives.
 */
final class CollectionEntryDepthTest extends TestCase
{
    private string $base;
    private CollectionsController $controller;
    /** @var array<string, mixed> */
    private array $admin = ['username' => 'ed', 'role' => 'admin'];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-entry-depth-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/collections', 0o700, true);

        file_put_contents($this->base . '/collections/post.json', json_encode([
            'label' => 'Blog posts',
            'titleField' => 'title',
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'body', 'type' => 'richtext'],
                ['name' => 'related', 'type' => 'reference', 'references' => 'post', 'multiple' => true],
            ],
        ]));

        $versions = new JsonVersionStore($this->base . '/versions');
        $storage = new VersioningStorage(new JsonStorage($this->base . '/content'), $versions);
        Publishable::register(['post']);

        $content = new ContentService($storage);
        $types = new JsonCollectionTypeRepository($this->base . '/collections');

        $this->controller = new CollectionsController(
            new CollectionService($content, $types, new SectionValidator()),
            new ReferenceResolver($content, $types),
            fn (): array => $this->admin,
            new HistoryService($storage, $versions),
            null,
            new BackReferenceService($types, $content),
        );

        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        $_GET = [];
        $_POST = [];
        $this->rrmdir($this->base);
    }

    public function testAnEntryReportsTheLanguagesItExistsIn(): void
    {
        $this->saveEntry('hello', ['title' => 'Hello'], null);
        $this->saveEntry('hello', ['title' => 'Hallo'], 'de');

        $_GET = [];
        $en = $this->controller->getEntry('post', 'hello');

        $this->assertContains('en', $en['availableLocales']);
        $this->assertContains('de', $en['availableLocales']);
    }

    public function testHistoryAccumulatesAndRestoreBringsBackAnEarlierEntry(): void
    {
        $this->saveEntry('hello', ['title' => 'First'], null);
        $this->saveEntry('hello', ['title' => 'Second'], null);

        $_GET = [];
        $list = $this->controller->listEntryVersions('post', 'hello');
        $this->assertArrayHasKey('data', $list);
        $this->assertGreaterThanOrEqual(2, count($list['data']));

        $older = null;
        foreach ($list['data'] as $version) {
            if (($version['title'] ?? null) === 'First') {
                $older = $version;
                break;
            }
        }
        $this->assertNotNull($older, 'Expected a version holding the first title.');

        $restored = $this->controller->restoreEntryVersion('post', 'hello', $older['id']);
        $this->assertSame('First', $restored['data']['entry']['data']['title']);
    }

    public function testHistoryIsScopedToTheEntrysLanguage(): void
    {
        $this->saveEntry('hello', ['title' => 'English one'], null);
        $this->saveEntry('hello', ['title' => 'German one'], 'de');

        $_GET = ['locale' => 'de'];
        $german = $this->controller->listEntryVersions('post', 'hello');

        foreach ($german['data'] as $version) {
            $this->assertNotSame('English one', $version['title'] ?? null);
        }
    }

    public function testHistoryRoutesReportUnknownCollections(): void
    {
        $result = $this->controller->listEntryVersions('nope', 'hello');
        $this->assertSame(404, $result['status']);
    }

    public function testBackReferencesListWhatPointsAtAnEntry(): void
    {
        $this->saveEntry('target', ['title' => 'Target'], null);
        $this->saveEntry('pointer', ['title' => 'Pointer', 'related' => ['target']], null);

        $_GET = [];
        $result = $this->controller->listBackReferences('post', 'target');

        $this->assertCount(1, $result['data']);
        $this->assertSame('pointer', $result['data'][0]['slug']);
        $this->assertSame('related', $result['data'][0]['field']);
    }

    public function testBackReferencesRequireAuthentication(): void
    {
        $this->admin = [];
        $result = $this->controller->listBackReferences('post', 'target');
        $this->assertSame(401, $result['status']);
    }

    public function testWithoutAHistoryServiceTheRoutesReportItAbsent(): void
    {
        $content = new ContentService(new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        ));
        $types = new JsonCollectionTypeRepository($this->base . '/collections');
        $controller = new CollectionsController(
            new CollectionService($content, $types, new SectionValidator()),
            new ReferenceResolver($content, $types),
            fn (): array => $this->admin,
        );

        $result = $controller->listEntryVersions('post', 'hello');
        $this->assertSame(501, $result['status']);
    }

    /**
     * Save an entry through the controller: create it if absent for that locale,
     * update it otherwise, so a second call at the same slug leaves history.
     *
     * @param array<string, mixed> $values
     */
    private function saveEntry(string $slug, array $values, ?string $locale): void
    {
        $_GET = $locale === null ? [] : ['locale' => $locale];
        $_POST = ['slug' => $slug, 'values' => $values];

        $existing = $this->controller->getEntry('post', $slug);
        if (($existing['status'] ?? 200) === 404) {
            $this->controller->createEntry('post');
        } else {
            $this->controller->updateEntry('post', $slug);
        }

        $_POST = [];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
