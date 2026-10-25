<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Migration;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Migration\WordPressWxrImporter;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

final class WordPressWxrImporterTest extends TestCase
{
    private string $dir;
    private WordPressWxrImporter $importer;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-wxr-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/collections', 0o700, true);
        file_put_contents($this->dir . '/collections/post.json', json_encode([
            'label' => 'Blog posts', 'titleField' => 'title',
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'body', 'type' => 'richtext', 'required' => true],
                ['name' => 'excerpt', 'type' => 'textarea'],
            ],
        ]));
        $storage = new VersioningStorage(
            new JsonStorage($this->dir . '/content'),
            new JsonVersionStore($this->dir . '/versions'),
        );
        $this->content = new ContentService($storage);
        $pages = new PageService($this->content, new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'));
        $collections = new CollectionService($this->content, new JsonCollectionTypeRepository($this->dir . '/collections'), new SectionValidator());
        $this->importer = new WordPressWxrImporter($pages, $collections);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testImports_page_and_post_as_working_copies_and_skips_unsupported_records(): void
    {
        $result = $this->importer->import([
            ['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'content' => '<p>Welcome</p>', 'id' => '9', 'status' => 'publish'],
            ['type' => 'post', 'slug' => 'story', 'title' => 'Story', 'content' => '<p>Text</p>', 'excerpt' => 'Short'],
            ['type' => 'attachment', 'slug' => 'photo', 'title' => 'Photo'],
        ], ['username' => 'importer', 'role' => 'admin'], 'en');

        self::assertSame([
            ['type' => 'page', 'slug' => 'home', 'title' => 'Home'],
            ['type' => 'post', 'slug' => 'story', 'title' => 'Story'],
        ], $result['created']);
        self::assertSame('publish', $this->content->draftPage('home', 'en')?->data['wordpressSource']['status']);
        self::assertSame('<p>Text</p>', $this->content->draft(ContentKey::for('post', 'story', 'en'))?->data['body']);
        self::assertSame('Unsupported WordPress content type.', $result['skipped'][0]['reason']);
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }
        @rmdir($path);
    }
}
