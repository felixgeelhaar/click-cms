<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * Previewing a collection entry.
 *
 * The preview route only matched a single-segment page slug, so an entry could
 * not be previewed at all: an author could draft a blog post and nobody — the
 * author included, and more to the point whoever had to approve it — could see
 * it rendered. For a CMS whose author role exists precisely to draft work for
 * someone else to release, that was the review step missing its subject.
 *
 * The whole risk here is the other direction, so that is what most of these
 * assert: preview shows unpublished work, and it must reach exactly the person
 * holding a token minted for *that* document and nobody else.
 */
final class EntryPreviewRouteTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-entry-preview-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }
        // The real section and collection definitions, so `post` carries the
        // route this whole feature hangs off.
        foreach (['sections', 'collections'] as $dir) {
            mkdir($this->base . '/config/' . $dir, 0o775, true);
            foreach (glob(dirname(__DIR__, 3) . '/config/' . $dir . '/*.json') ?: [] as $f) {
                copy($f, $this->base . '/config/' . $dir . '/' . basename($f));
            }
        }
        file_put_contents($this->base . '/config/core.json', json_encode(['core' => [
            'languages' => ['default' => 'en', 'available' => ['en']],
        ]]));

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();

        $this->draftPost('unseen-draft', 'A post nobody has approved', 'THE UNPUBLISHED BODY');
        $this->draftPost('other-draft', 'Another draft', 'A DIFFERENT SECRET');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        self::removeTree($this->base);
    }

    private function key(string $slug): ContentKey
    {
        return ContentKey::for('post', $slug, $this->app->getContentService()->defaultLocale());
    }

    /** Saved and never published — which is what a draft entry is. */
    private function draftPost(string $slug, string $title, string $body): void
    {
        $this->app->getContentService()->save(Content::create($this->key($slug), [
            'title' => $title,
            'excerpt' => 'Still being written.',
            'body' => '<p>' . $body . '</p>',
        ]));
    }

    private function tokenFor(string $slug): string
    {
        return (new PreviewLinks($this->base . '/data/preview-secret'))
            ->issue($this->key($slug))['token'];
    }

    private function request(string $uri): string
    {
        ob_start();
        try {
            (new \ReflectionMethod($this->app, 'handleRequest'))
                ->invoke($this->app, (string) parse_url($uri, PHP_URL_PATH), 'GET');
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /* --------------------------------------------------------- it works -- */

    public function testASignedLinkShowsADraftEntryRendered(): void
    {
        $token = $this->tokenFor('unseen-draft');
        $_GET['token'] = $token;

        $html = $this->request('/preview/blog/unseen-draft?token=' . $token);

        $this->assertStringContainsString('THE UNPUBLISHED BODY', $html);
        $this->assertStringContainsString('A post nobody has approved', $html);
        // Rendered as a document, not a fragment — the point is seeing it as a
        // visitor would.
        $this->assertStringContainsString('<html', $html);
    }

    /* ------------------------------------------------- and it stays shut -- */

    public function testTheDraftIsNotAtItsPublicAddress(): void
    {
        $this->assertStringNotContainsString('THE UNPUBLISHED BODY', $this->request('/blog/unseen-draft'));
    }

    public function testNoTokenShowsNothing(): void
    {
        $this->assertStringNotContainsString('THE UNPUBLISHED BODY', $this->request('/preview/blog/unseen-draft'));
    }

    /**
     * The property that matters most: a token is minted for one document. If it
     * opened any entry, one shared review link would expose every draft the site
     * has.
     */
    public function testATokenForOneEntryDoesNotOpenAnother(): void
    {
        $token = $this->tokenFor('unseen-draft');
        $_GET['token'] = $token;

        $html = $this->request('/preview/blog/other-draft?token=' . $token);

        $this->assertStringNotContainsString('A DIFFERENT SECRET', $html);
    }

    public function testAGarbledTokenShowsNothing(): void
    {
        $_GET['token'] = 'not-a-real-token';

        $this->assertStringNotContainsString(
            'THE UNPUBLISHED BODY',
            $this->request('/preview/blog/unseen-draft?token=not-a-real-token')
        );
    }

    /** An address in a collection that declares no route is not previewable. */
    public function testAnUnroutedCollectionHasNoPreviewAddressEither(): void
    {
        $this->app->getContentService()->save(Content::create(
            ContentKey::for('team-member', 'someone', $this->app->getContentService()->defaultLocale()),
            ['name' => 'Someone', 'bio' => 'A PRIVATE BIO']
        ));

        $this->assertStringNotContainsString(
            'A PRIVATE BIO',
            $this->request('/preview/team-member/someone')
        );
    }

    /** A page preview must go on working exactly as it did. */
    public function testAPagePreviewStillWorks(): void
    {
        $content = $this->app->getContentService();
        $content->save(Content::create(
            ContentKey::page('draft-page', $content->defaultLocale()),
            ['title' => 'Draft page', 'sections' => [
                ['type' => 'rich-text', 'values' => ['body' => 'PAGE DRAFT BODY']],
            ]]
        ));

        $token = (new PreviewLinks($this->base . '/data/preview-secret'))
            ->issue(ContentKey::page('draft-page', $content->defaultLocale()))['token'];
        $_GET['token'] = $token;

        $this->assertStringContainsString(
            'PAGE DRAFT BODY',
            $this->request('/preview/draft-page?token=' . $token)
        );
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
