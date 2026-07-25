<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Seed;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Application\Seed\ExampleSite;
use Click\Cms\Application\Seed\SiteSeeder;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The example content seeder.
 *
 * Two properties carry the feature. The first is that the seeded content is
 * *accepted* — it goes through the same services the admin UI posts to, so a
 * section schema that no longer matches its own example fails here rather than
 * on somebody's first install. The second is that seeding never destroys: it is
 * run against sites that already have content, and the only unforgivable
 * outcome is overwriting some of it.
 */
final class SiteSeederTest extends TestCase
{
    private string $dir;
    private ContentService $content;
    private SiteSeeder $seeder;

    /** @var array<string, mixed> */
    private array $admin = ['username' => 'admin', 'role' => 'admin'];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-seed-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $repoRoot = dirname(__DIR__, 3);

        // What the application registers at boot. Without it a collection entry
        // is live the instant it is saved, and the seeder's publish() calls for
        // entries would be tested as no-ops.
        Publishable::register(['post', 'team-member']);

        // The versioned stack the application boots with, not a bare backend.
        // Draft-and-publish lives in that decorator, so on a bare backend every
        // save is instantly live — which would make the publishing assertions
        // below pass without the seeder ever calling publish(), and would make
        // "do not overwrite a draft" untestable.
        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->dir . '/content'),
            new JsonVersionStore($this->dir . '/versions', RetentionPolicy::keeping(5)),
            static fn (): string => 'admin',
        ));
        $this->seeder = new SiteSeeder(
            $this->content,
            new PageService(
                $this->content,
                new JsonSectionTypeRepository($repoRoot . '/config/sections'),
                new SectionValidator(),
            ),
            new CollectionService(
                $this->content,
                new JsonCollectionTypeRepository($repoRoot . '/config/collections'),
                new SectionValidator(),
            ),
            new MediaService($this->dir . '/media'),
        );
    }

    protected function tearDown(): void
    {
        // The registry is static; leaving it dirty would make an unrelated test
        // that runs after this one see publishable types it never registered.
        Publishable::reset();
        self::removeTree($this->dir);
    }

    /* ----------------------------------------------------- it is accepted -- */

    /**
     * The load-bearing test. Every page, entry and picture in the example site
     * is put through the real validators; a single rejection means the shipped
     * schemas and the shipped example have drifted apart.
     */
    public function testTheWholeExampleSiteIsAcceptedByTheRealServices(): void
    {
        $report = $this->seeder->seed($this->admin);

        $this->assertSame([], $report->failureMessages(), 'the example site must validate against its own schemas');
        $this->assertFalse($report->hasFailures());
    }

    public function testEverySectionTypeTheProjectShipsAppearsInTheExample(): void
    {
        $used = [];
        foreach (ExampleSite::pages() as $page) {
            foreach ($page['sections'] as $section) {
                $used[$section['type']] = true;
            }
        }

        $shipped = array_map(
            static fn (string $path): string => basename($path, '.json'),
            glob(dirname(__DIR__, 3) . '/config/sections/*.json') ?: []
        );

        $this->assertSame(
            [],
            array_diff($shipped, array_keys($used)),
            'a section type nobody can see an example of may as well not ship'
        );
    }

    public function testThePagesAreLiveRatherThanLeftAsDrafts(): void
    {
        $this->seeder->seed($this->admin);

        // Published, not merely saved: an example site whose every page is a
        // draft shows a visitor an empty site.
        $published = array_map(
            static fn (Content $page): string => $page->slug(),
            $this->content->publishedPages()
        );

        foreach (array_keys(ExampleSite::pages()) as $slug) {
            $this->assertContains($slug, $published);
        }
    }

    public function testImageTokensAreReplacedByRealMediaIds(): void
    {
        $this->seeder->seed($this->admin);

        $home = $this->content->page('home');
        $this->assertNotNull($home);

        $image = $home->data['sections'][0]['values']['image'] ?? null;
        $this->assertIsString($image);
        $this->assertStringStartsNotWith(
            ExampleSite::MEDIA_TOKEN_PREFIX,
            $image,
            'an unresolved token would be baked into content as a broken reference'
        );

        $media = new MediaService($this->dir . '/media');
        $this->assertNotNull($media->find($image), 'the id must name a picture that exists');
    }

    /** Tokens nested inside a repeater resolve too, not just top-level fields. */
    public function testImageTokensInsideRepeatersAreResolved(): void
    {
        $this->seeder->seed($this->admin);

        $cards = $this->content->page('home')?->data['sections'][2]['values']['cards'] ?? [];
        $this->assertNotEmpty($cards);

        foreach ($cards as $card) {
            $this->assertArrayHasKey('image', $card);
            $this->assertStringStartsNotWith(ExampleSite::MEDIA_TOKEN_PREFIX, $card['image']);
        }
    }

    public function testSeededPicturesCarryAltText(): void
    {
        $this->seeder->seed($this->admin);

        $media = new MediaService($this->dir . '/media');
        $this->assertNotEmpty($media->all());

        foreach ($media->all() as $item) {
            $this->assertNotSame('', $item->alt, "{$item->originalName} was seeded without a description");
        }
    }

    public function testPostsReferenceTeamMembersThatActuallyExist(): void
    {
        $this->seeder->seed($this->admin);

        foreach (array_keys(ExampleSite::posts()) as $slug) {
            $post = $this->content->get(ContentKey::for('post', $slug, $this->content->defaultLocale()));
            $this->assertNotNull($post);

            $author = $post->data['author'] ?? null;
            $this->assertIsString($author);
            $this->assertNotNull(
                $this->content->get(ContentKey::for('team-member', $author, $this->content->defaultLocale())),
                "post/{$slug} names an author who was never seeded"
            );
        }
    }

    /**
     * Validating is not the same as rendering. A seeded site whose pictures
     * come out as unresolved `<img>` tags passes every assertion above and still
     * looks broken on the screen the newcomer actually opens, so the renderer
     * gets the last word.
     */
    public function testTheSeededHomePageRendersWithItsPicturesResolved(): void
    {
        $this->seeder->seed($this->admin);

        $renderer = new SectionRenderer(
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'),
            new MediaService($this->dir . '/media'),
        );

        $html = $renderer->render($this->content->page('home') ?? self::fail('home was not seeded'));

        $this->assertStringContainsString('Furniture made to be repaired', $html);
        $this->assertStringContainsString('The shop in numbers', $html);
        $this->assertStringContainsString('Book a visit', $html);

        // A resolved reference is served through the library, which knows the
        // file extension; an unresolved one emits the bare stored value, and the
        // stored value is an id with no extension. So `.svg` in the src is the
        // discriminator between the two branches. (No srcset to check for here:
        // an SVG has no variant ladder, because it does not need one.)
        $this->assertMatchesRegularExpression('#src="/api/media/file/[a-z0-9-]+\.svg"#', $html);
        $this->assertStringNotContainsString(ExampleSite::MEDIA_TOKEN_PREFIX, $html);

        // The image description belongs in the alt attribute and nowhere else.
        // Rendered as a paragraph as well, it is read out twice by a screen
        // reader and shown as stray body copy to everyone else.
        $description = 'The workshop floor, with benches under high windows.';
        $this->assertStringContainsString('alt="' . $description . '"', $html);
        $this->assertStringNotContainsString('>' . $description . '<', $html);
    }

    /* ------------------------------------------------- it never overwrites -- */

    public function testSeedingTwiceChangesNothingTheSecondTime(): void
    {
        $this->seeder->seed($this->admin);
        $before = $this->snapshot();

        $second = $this->seeder->seed($this->admin);

        $this->assertTrue($second->wasNoOp(), 'a second run must create nothing');
        $this->assertSame([], $second->failureMessages());
        $this->assertSame($before, $this->snapshot(), 'a second run must not change a single byte');
    }

    /**
     * The property that matters most on a real site: content that happens to
     * share an address with the example is left exactly as it was.
     */
    public function testAnExistingPageAtTheSameAddressIsLeftUntouched(): void
    {
        $mine = Content::create(
            ContentKey::page('home', $this->content->defaultLocale()),
            ['title' => 'My own home page', 'sections' => []]
        );
        $this->content->save($mine);

        $report = $this->seeder->seed($this->admin);

        $this->assertSame('My own home page', $this->content->draftPage('home')?->title());
        $this->assertContains('page/home', $report->skippedItems());
        $this->assertSame([], $report->failureMessages());
    }

    /** An unpublished draft holds the address as firmly as a live page. */
    public function testAnUnpublishedDraftIsNotOverwrittenEither(): void
    {
        $draft = Content::create(
            ContentKey::page('contact', $this->content->defaultLocale()),
            ['title' => 'Work in progress', 'sections' => []]
        );
        $this->content->save($draft);
        $this->assertNull($this->content->page('contact'), 'precondition: the draft is not live');

        $this->seeder->seed($this->admin);

        $this->assertSame('Work in progress', $this->content->draftPage('contact')?->title());
    }

    /**
     * Pictures are matched by their original filename, the only stable handle
     * the library keeps — an id is generated per upload, so matching on one
     * would seed a duplicate set of pictures on every run.
     */
    public function testASecondRunDoesNotDuplicateThePictures(): void
    {
        $this->seeder->seed($this->admin);
        $count = count((new MediaService($this->dir . '/media'))->all());

        $this->seeder->seed($this->admin);

        $this->assertSame($count, count((new MediaService($this->dir . '/media'))->all()));
        $this->assertSame(count(ExampleSite::media()), $count);
    }

    public function testAnExistingMenuIsNotReplaced(): void
    {
        $key = ContentKey::for('menu', 'main', $this->content->defaultLocale());
        $this->content->save(Content::create($key, ['name' => 'Mine', 'items' => []]));

        $this->seeder->seed($this->admin);

        $this->assertSame('Mine', $this->content->get($key)?->data['name']);
    }

    /* ----------------------------------------------------------- helpers -- */

    /**
     * Every file under the working directory with its contents hashed — the
     * comparison that catches a rewrite the report failed to mention.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
