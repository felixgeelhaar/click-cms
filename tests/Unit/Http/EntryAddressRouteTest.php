<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * A collection entry's public address, driven through the kernel.
 *
 * Everything here goes in at `handleRequest()`, the same door a request uses,
 * because every bug this is guarding against lives in the routing and not in the
 * pieces: which handler claims a path, what a draft answers, and what happens to
 * a page whose slug is also a collection's route. A router that is correct in
 * isolation and wired one step too late is a site serving drafts.
 *
 * The one rule that must never bend: **only published entries are served.** A
 * draft at its own address answers exactly as a slug nobody ever used, and the
 * two responses are compared byte for byte here rather than merely both being
 * 404s.
 */
final class EntryAddressRouteTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-entry-route-' . bin2hex(random_bytes(6));

        foreach (['content', 'data', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }
        mkdir($this->base . '/config/sections', 0o775, true);
        mkdir($this->base . '/config/collections', 0o775, true);

        $root = dirname(__DIR__, 3);
        foreach (glob($root . '/config/sections/*.json') ?: [] as $file) {
            copy($file, $this->base . '/config/sections/' . basename($file));
        }
        foreach (glob($root . '/config/collections/*.json') ?: [] as $file) {
            copy($file, $this->base . '/config/collections/' . basename($file));
        }

        file_put_contents($this->base . '/config/core.json', json_encode(['core' => [
            // The cache off for these tests: what is being pinned here is routing,
            // and the cache has its own test. Its own test is where the key
            // collision between a page and an entry lives.
            'cache' => ['enabled' => false],
            'languages' => ['default' => 'en', 'available' => ['en', 'de']],
        ]]));

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        Publishable::reset();
        self::removeTree($this->base);
    }

    /** @param array<string, mixed> $data */
    private function entry(string $type, string $slug, array $data, bool $publish = true, ?string $locale = null): void
    {
        $key = ContentKey::for($type, $slug, $locale);
        $this->app->getContentService()->save(Content::create($key, $data + ['slug' => $slug]));

        if ($publish) {
            $this->app->getContentService()->publish($key);
        }
    }

    /** @param array<string, mixed> $data */
    private function page(string $slug, array $data, ?string $locale = null): void
    {
        $key = ContentKey::page($slug, $locale);
        $this->app->getContentService()->save(Content::create($key, $data));
        $this->app->getContentService()->publish($key);
    }

    /** @return array{status: int, body: string} */
    private function get(string $uri): array
    {
        http_response_code(200);

        ob_start();
        try {
            $response = (new \ReflectionMethod($this->app, 'handleRequest'))->invoke($this->app, $uri, 'GET');
        } finally {
            $echoed = (string) ob_get_clean();
        }

        return [
            'status' => http_response_code(),
            'body' => ($response['raw'] ?? false) ? $echoed : (string) json_encode($response),
        ];
    }

    private function aPost(string $slug, string $title, bool $publish = true, ?string $locale = null): void
    {
        $this->entry('post', $slug, [
            'title' => $title,
            'excerpt' => 'A short summary.',
            'body' => '<p>THE BODY OF ' . $title . '</p>',
        ], $publish, $locale);
    }

    /* ------------------------------------------------------- the address works -- */

    public function testAPublishedEntryIsServedAtItsOwnAddress(): void
    {
        $this->aPost('why-we-stopped-staining', 'Why we stopped staining');

        $response = $this->get('/blog/why-we-stopped-staining');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Why we stopped staining', $response['body']);
        $this->assertStringContainsString('THE BODY OF Why we stopped staining', $response['body']);
        // The site's own document, not a bare fragment: an entry is a page of the
        // site and gets the shell, the language attribute and the theme.
        $this->assertStringContainsString('<!doctype html>', strtolower($response['body']));
        $this->assertStringContainsString('lang="en"', $response['body']);
    }

    public function testTheEntrysRichTextIsRenderedAsMarkupAndSanitised(): void
    {
        $this->entry('post', 'benches', [
            'title' => 'Benches',
            'body' => '<p>Kept <strong>bold</strong>.</p><script>alert(1)</script>',
        ]);

        $body = $this->get('/blog/benches')['body'];

        $this->assertStringContainsString('<strong>bold</strong>', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function testAnEntryTitleCarryingMarkupIsEscapedEverywhereItAppears(): void
    {
        $this->entry('post', 'hostile', [
            'title' => '<script>alert(1)</script>',
            'body' => '<p>Prose.</p>',
        ]);

        $body = $this->get('/blog/hostile')['body'];

        // Both the document title and the heading come from the same value, and a
        // miss in either is a stored XSS on a public page.
        $this->assertStringNotContainsString('<script>alert', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testACollectionWithNoRouteHasNoPublicAddress(): void
    {
        // Team members are admin-only content, and an upgrade must not quietly
        // give every one of them a public page.
        $this->entry('team-member', 'jun-park', ['name' => 'Jun Park', 'bio' => 'Joiner.']);

        $this->assertSame(404, $this->get('/team-member/jun-park')['status']);
        $this->assertSame(404, $this->get('/team/jun-park')['status']);
    }

    /* ------------------------------------------------------------ publication -- */

    /**
     * The test this feature is not allowed to fail. A draft entry is not merely
     * hidden — it answers identically to an address that has never existed, so
     * nobody can tell from the outside that there is unpublished work here.
     */
    public function testADraftEntryIs404AndIndistinguishableFromNothingAtAll(): void
    {
        $this->aPost('the-unfinished-one', 'THE UNPUBLISHED HEADLINE', publish: false);

        $draft = $this->get('/blog/the-unfinished-one');
        $absent = $this->get('/blog/no-such-post-was-ever-written');

        $this->assertSame(404, $draft['status']);
        $this->assertStringNotContainsString('THE UNPUBLISHED HEADLINE', $draft['body']);
        $this->assertSame($absent['body'], $draft['body'], 'a draft must not be distinguishable from an absent entry');

        // And it really is a draft, not a failed write: the editor can still see it.
        $this->assertNotNull(
            $this->app->getContentService()->draft(ContentKey::for('post', 'the-unfinished-one'))
        );
    }

    public function testAnUnpublishedEntryStopsBeingServed(): void
    {
        $this->aPost('taken-down', 'Taken down');
        $this->assertSame(200, $this->get('/blog/taken-down')['status']);

        $this->app->getContentService()->unpublish(ContentKey::for('post', 'taken-down'));

        $this->assertSame(404, $this->get('/blog/taken-down')['status']);
    }

    /* -------------------------------------------------------------- precedence -- */

    /**
     * Routing posts at `blog` must not take `/blog` away from a page called
     * `blog`. An entry address is always a route plus a slug, so the bare route
     * still belongs to whatever page holds that slug — which is exactly how a
     * listing page and its entries live together.
     */
    public function testAPageWhoseSlugIsAlsoARouteIsStillServed(): void
    {
        $this->page('blog', ['title' => 'The Journal', 'content' => 'THE LISTING PAGE']);
        $this->aPost('an-entry', 'An entry');

        $atTheRoute = $this->get('/blog');
        $this->assertSame(200, $atTheRoute['status']);
        $this->assertStringContainsString('THE LISTING PAGE', $atTheRoute['body']);

        $atTheEntry = $this->get('/blog/an-entry');
        $this->assertSame(200, $atTheEntry['status']);
        $this->assertStringContainsString('THE BODY OF An entry', $atTheEntry['body']);
        $this->assertStringNotContainsString('THE LISTING PAGE', $atTheEntry['body']);
    }

    public function testAPageWinsAPathAnEntryRouteWouldOtherwiseClaim(): void
    {
        // Pages are resolved first. Page slugs cannot contain a slash today, so the
        // two address shapes cannot overlap — this pins the ordering that keeps
        // that true if they ever can.
        $this->page('blog', ['title' => 'Journal', 'content' => 'PAGE CONTENT']);

        $this->assertStringContainsString('PAGE CONTENT', $this->get('/blog')['body']);
    }

    public function testTheApplicationsOwnPathsAreNotSwallowed(): void
    {
        $this->aPost('an-entry', 'An entry');

        // The API still answers as itself — as JSON, from the API guard — rather
        // than being read as a path under some `api` route.
        $api = $this->get('/api/collections');
        $this->assertJson($api['body']);
        $this->assertStringNotContainsString('<!doctype', strtolower($api['body']));

        // A preview URL shaped like an entry address is not an entry address: the
        // preview route owns `/preview/…` and refuses a multi-segment page slug.
        $preview = $this->get('/preview/blog/an-entry');
        $this->assertSame(404, $preview['status']);
        $this->assertStringNotContainsString('THE BODY OF An entry', $preview['body']);
    }

    public function testARedirectRuleStillFiresForAnEntryPathWithNoEntry(): void
    {
        // Content that is here beats a rule about where content used to be, and a
        // rule still catches a path with nothing at it.
        $this->app->getContentService()->save(Content::create(
            ContentKey::for('redirect', 'rules'),
            ['redirects' => [['from' => '/blog/gone', 'to' => '/blog/an-entry', 'permanent' => true]]],
        ));
        $this->aPost('an-entry', 'An entry');

        $this->assertSame(
            ['redirect' => '/blog/an-entry', 'status' => 301],
            json_decode($this->get('/blog/gone')['body'], true)
        );

        // And the rule does not steal a path where an entry actually lives.
        $this->app->getContentService()->save(Content::create(
            ContentKey::for('redirect', 'rules'),
            ['redirects' => [['from' => '/blog/an-entry', 'to' => '/', 'permanent' => true]]],
        ));

        $atTheEntry = $this->get('/blog/an-entry');
        $this->assertSame(200, $atTheEntry['status']);
        $this->assertStringContainsString('THE BODY OF An entry', $atTheEntry['body']);
    }

    /* ------------------------------------------------------------------ locale -- */

    public function testTheLanguagePrefixWorksTheWayItDoesForPages(): void
    {
        $this->aPost('zwei-stuehle', 'Zwei Stühle', locale: 'de');

        $response = $this->get('/de/blog/zwei-stuehle');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Zwei Stühle', $response['body']);
        $this->assertStringContainsString('lang="de"', $response['body']);
    }

    public function testAMissingTranslationFallsBackExactlyAsAPageDoes(): void
    {
        $this->aPost('why-we-stopped-staining', 'Why we stopped staining');

        $response = $this->get('/de/blog/why-we-stopped-staining');

        $this->assertSame(200, $response['status']);
        // Served in English, and saying so: a German URL showing English prose must
        // not claim to be German, or a screen reader pronounces it as German.
        $this->assertStringContainsString('lang="en"', $response['body']);
        $this->assertStringContainsString('Why we stopped staining', $response['body']);
    }

    public function testADraftTranslationDoesNotLeakThroughTheFallback(): void
    {
        $this->aPost('why-we-stopped-staining', 'THE ENGLISH DRAFT', publish: false);
        $this->aPost('why-we-stopped-staining', 'DER DEUTSCHE ENTWURF', publish: false, locale: 'de');

        $response = $this->get('/de/blog/why-we-stopped-staining');

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('DRAFT', $response['body']);
        $this->assertStringNotContainsString('ENTWURF', $response['body']);
    }

    /* ----------------------------------------------------- the listing on a page -- */

    /**
     * The other half of the feature, over the real request path: a page carrying a
     * listing section shows the collection, and each entry links to the address
     * the router answers.
     */
    public function testAPageWithAListingSectionShowsTheCollectionAndLinksResolve(): void
    {
        $this->aPost('why-we-stopped-staining', 'Why we stopped staining');
        $this->aPost('a-table-that-outlives-you', 'A table that outlives you');
        $this->aPost('not-ready', 'THE UNPUBLISHED HEADLINE', publish: false);

        $this->page('journal', [
            'title' => 'Journal',
            'sections' => [[
                'type' => 'collection-list',
                'values' => ['heading' => 'From the journal', 'collection' => 'post', 'limit' => 10],
            ]],
        ]);

        $listing = $this->get('/journal');

        $this->assertSame(200, $listing['status']);
        $this->assertStringContainsString('From the journal', $listing['body']);
        $this->assertStringContainsString('href="/blog/why-we-stopped-staining"', $listing['body']);
        $this->assertStringContainsString('href="/blog/a-table-that-outlives-you"', $listing['body']);
        $this->assertStringNotContainsString('THE UNPUBLISHED HEADLINE', $listing['body']);

        // Every link the listing rendered is an address that answers — the property
        // that makes this a feature rather than a page of dead links.
        preg_match_all('#href="(/blog/[^"]+)"#', $listing['body'], $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $href) {
            $this->assertSame(200, $this->get($href)['status'], $href);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
