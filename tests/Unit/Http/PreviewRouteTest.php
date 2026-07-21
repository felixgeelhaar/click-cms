<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Core\Application;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use PHPUnit\Framework\TestCase;

/**
 * The guard on the preview route, exercised through the kernel rather than
 * against the token in isolation.
 *
 * A token that verifies correctly is worth nothing if the route forgets to ask,
 * so these drive the same entry point a request does. The rule being pinned
 * down is a single sentence: an anonymous visitor with no valid token can reach
 * no unpublished content, by any address.
 */
final class PreviewRouteTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-preview-route-' . bin2hex(random_bytes(6));

        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();

        $this->savePage('secret-plans', 'Secret Plans', false, 'THE UNPUBLISHED SENTENCE');
        $this->savePage('about', 'About', true, 'A PUBLISHED SENTENCE');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    /**
     * Rewritten for draft-and-publish. Unpublished used to mean a `status`
     * field on a document that was in `content/` either way; it now means the
     * document is not in `content/` at all, and publishing is the act that puts
     * it there.
     */
    private function savePage(string $slug, string $title, bool $publish, string $body): void
    {
        $this->app->getContentService()->save(Content::create(ContentKey::page($slug), [
            'title' => $title,
            'content' => $body,
        ]));

        if ($publish) {
            $this->app->getContentService()->publish(ContentKey::page($slug));
        }
    }

    /** Drive the kernel the way a request does, and collect what it wrote. */
    private function request(string $uri): string
    {
        ob_start();

        try {
            $reflection = new \ReflectionMethod($this->app, 'handleRequest');
            $reflection->invoke($this->app, $uri, 'GET');
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function tokenFor(string $slug, ?Locale $locale = null): string
    {
        return (new PreviewLinks($this->base . '/data/preview-secret'))
            ->issue(ContentKey::page($slug, $locale))['token'];
    }

    /** Put a real session on disk and hand its identifier to the request. */
    private function signIn(string $role, bool $mustChangePassword = false): void
    {
        $id = bin2hex(random_bytes(32));
        mkdir($this->base . '/data/sessions', 0o700, true);

        file_put_contents(
            $this->base . '/data/sessions/' . $id . '.json',
            json_encode([
                'username' => 'ann',
                'expiresAt' => time() + 3600,
                'lastActivity' => time(),
                'user' => [
                    'username' => 'ann',
                    'role' => $role,
                    'mustChangePassword' => $mustChangePassword,
                ],
            ])
        );

        $_COOKIE[SessionStore::COOKIE] = $id;
    }

    /* ------------------------------------------------- the public site -- */

    /**
     * The regression this whole capability rests on. A draft used to render at
     * its public address to anybody who guessed it, which made "unpublished" a
     * statement about intent rather than about access.
     */
    public function testAnUnpublishedPageIsNotServedAtItsPublicAddress(): void
    {
        $output = $this->request('/secret-plans');

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    /**
     * The rule stated at the level a visitor experiences it: an edit to a
     * published page is not published by having been saved.
     *
     * Driven through the kernel rather than the storage decorator, because that
     * is where somebody would have to remember to keep the two apart, and a
     * later refactor of the render path is exactly what could quietly stop
     * doing so.
     */
    public function testSavingAnEditToAPublishedPageDoesNotChangeThePublicPage(): void
    {
        $this->assertStringContainsString('A PUBLISHED SENTENCE', $this->request('/about'));

        $this->savePage('about', 'About', false, 'AN UNPUBLISHED REWRITE');

        $public = $this->request('/about');
        $this->assertStringContainsString('A PUBLISHED SENTENCE', $public);
        $this->assertStringNotContainsString('AN UNPUBLISHED REWRITE', $public);

        // Publishing is what closes the gap, and nothing else does.
        $this->app->getContentService()->publish(ContentKey::page('about'));

        $this->assertStringContainsString('AN UNPUBLISHED REWRITE', $this->request('/about'));
    }

    /**
     * Preview is the one place the pending edit must be visible, which is what
     * it was built for and could not do while a page had one stored document.
     */
    public function testPreviewShowsThePendingEditRatherThanTheLivePage(): void
    {
        $this->savePage('about', 'About', false, 'AN UNPUBLISHED REWRITE');

        $_GET['token'] = $this->tokenFor('about');
        $preview = $this->request('/preview/about?token=' . $_GET['token']);

        $this->assertStringContainsString('AN UNPUBLISHED REWRITE', $preview);
        // And says so, because an editor who thinks this is live will never
        // press Publish.
        $this->assertStringContainsString('these changes are not', $preview);
    }

    public function testTakingAPageDownMakesItAFourOhFourAgain(): void
    {
        $this->app->getContentService()->unpublish(ContentKey::page('about'));

        $output = $this->request('/about');

        $this->assertStringNotContainsString('A PUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    public function testAPublishedPageIsStillServedPublicly(): void
    {
        $output = $this->request('/about');

        $this->assertStringContainsString('A PUBLISHED SENTENCE', $output);
        $this->assertStringNotContainsString('Preview', $output);
    }

    /* --------------------------------------------- preview, unauthorised -- */

    public function testAnAnonymousVisitorWithNoTokenGetsNothing(): void
    {
        $output = $this->request('/preview/secret-plans');

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    public function testAnInventedTokenGetsNothing(): void
    {
        $_GET['token'] = (time() + 3600) . '.' . str_repeat('f', 64);

        $output = $this->request('/preview/secret-plans?token=' . $_GET['token']);

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    /**
     * A link handed to a proofreader for one page must not become a key to
     * everything else that is unfinished.
     */
    public function testATokenForOnePageDoesNotOpenAnother(): void
    {
        $this->savePage('other-secret', 'Other', false, 'ANOTHER UNPUBLISHED SENTENCE');

        $_GET['token'] = $this->tokenFor('other-secret');

        $output = $this->request('/preview/secret-plans?token=' . $_GET['token']);

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    /**
     * A page exists once per language, so a link to the finished German version
     * must not also unlock the English draft nobody has proofread. Signing the
     * slug alone made both the same subject.
     */
    public function testATokenForOneLanguageDoesNotOpenAnother(): void
    {
        $this->app->getContentService()->save(Content::create(
            ContentKey::page('secret-plans', Locale::fromString('de')),
            ['title' => 'Geheim', 'content' => 'DIE UNVEROEFFENTLICHTE ZEILE']
        ));

        $_GET['token'] = $this->tokenFor('secret-plans', Locale::fromString('de'));

        $output = $this->request('/preview/secret-plans?token=' . $_GET['token']);

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    public function testAnExpiredTokenGetsNothing(): void
    {
        $links = new PreviewLinks($this->base . '/data/preview-secret', 1);
        $_GET['token'] = $links->issue(ContentKey::page('secret-plans'))['token'];
        sleep(2);

        $output = $this->request('/preview/secret-plans?token=' . $_GET['token']);

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
        $this->assertStringContainsString('Page not found', $output);
    }

    /**
     * An account that has not yet replaced the seeded password may do nothing
     * anywhere, and preview is not carved out of that.
     */
    public function testAnAccountOwingAPasswordChangeCannotPreview(): void
    {
        $this->signIn('admin', mustChangePassword: true);

        $output = $this->request('/preview/secret-plans');

        $this->assertStringNotContainsString('THE UNPUBLISHED SENTENCE', $output);
    }

    /* ----------------------------------------------- preview, authorised -- */

    public function testAValidTokenRendersTheUnpublishedPage(): void
    {
        $_GET['token'] = $this->tokenFor('secret-plans');

        $output = $this->request('/preview/secret-plans?token=' . $_GET['token']);

        $this->assertStringContainsString('THE UNPUBLISHED SENTENCE', $output);
    }

    public function testASignedInEditorNeedsNoToken(): void
    {
        $this->signIn('editor');

        $output = $this->request('/preview/secret-plans');

        $this->assertStringContainsString('THE UNPUBLISHED SENTENCE', $output);
    }

    /**
     * Previews get screenshotted and forwarded, by which point the address bar
     * is gone. The warning has to be on the page itself.
     */
    public function testAPreviewSaysOnThePageThatItIsNotLive(): void
    {
        $_GET['token'] = $this->tokenFor('secret-plans');

        $output = $this->request('/preview/secret-plans?token=' . $_GET['token']);

        $this->assertStringContainsString('this is not the published site', $output);
        $this->assertStringContainsString('This page is not published', $output);
        $this->assertStringContainsString('noindex', $output);
    }

    /**
     * Preview must not be a second renderer, or what an editor approves is not
     * what visitors get. Same markup, from the same code path.
     */
    public function testPreviewRendersThroughTheSameRendererAsThePublicSite(): void
    {
        $this->savePage('twin', 'Twin', true, 'IDENTICAL BODY');

        $public = $this->request('/twin');

        $_GET['token'] = $this->tokenFor('twin');
        $preview = $this->request('/preview/twin?token=' . $_GET['token']);

        // Strip the banner the preview adds and the two documents are the same.
        $withoutBanner = preg_replace('#<div role="status".*?</div>#s', '', $preview);
        $withoutBanner = str_replace(['<title>Preview: ', "\n    <meta name=\"robots\" content=\"noindex, nofollow, noarchive\">"], ['<title>', ''], (string) $withoutBanner);

        $this->assertSame($public, $withoutBanner);
    }

    /* ------------------------------------------------------- path safety -- */

    /**
     * The slug reaches storage, so anything that is not a slug this application
     * could itself have made is refused before it is used to build a key.
     */
    public function testATraversingSlugIsRefused(): void
    {
        foreach (['../../config/core.json', '..%2f..%2fetc%2fpasswd', 'Secret-Plans', ''] as $slug) {
            $output = $this->request('/preview/' . $slug . '?token=x');

            $this->assertStringContainsString('Page not found', $output, $slug);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
