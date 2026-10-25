<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Translation\TranslationFreshnessService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\ContentRevision;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\TranslationFreshnessController;
use Click\Cms\Http\ApiGuard;
use Click\Cms\Http\PagesController;
use Click\Cms\Http\SensitiveResponseCache;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Translation\JsonTranslationReviewStore;
use PHPUnit\Framework\TestCase;

final class TranslationFreshnessControllerTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private TranslationFreshnessController $controller;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-translation-controller-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);
        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $pages = new PageService($this->content, new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'));
        $freshness = new TranslationFreshnessService($this->content, new JsonTranslationReviewStore($this->base . '/data/translation-reviews'));
        $this->controller = new TranslationFreshnessController($pages, $freshness);
        $this->save('home', 'en', 'alice');
        $this->save('home', 'de', 'alice');
        $this->save('other', 'en', 'bob');
        $this->save('other', 'de', 'bob');
    }

    protected function tearDown(): void { $this->removeTree($this->base); }

    private function save(string $slug, string $locale, string $owner): void
    {
        $this->content->save(Content::create(ContentKey::page($slug, $locale), ['title' => ucfirst($slug), 'owner' => $owner]));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->removeTree($path . '/' . $entry);
        @rmdir($path);
    }

    public function testAnonymousRequestsAndViewerWritesAreRefused(): void
    {
        self::assertSame(401, $this->controller->read(['slug' => 'home', 'sourceLocale' => 'en', 'targetLocale' => 'de'], [])['status']);
        self::assertSame(403, $this->controller->mark([
            'slug' => 'home', 'sourceLocale' => 'en', 'targetLocale' => 'de', 'sourceRevision' => 'a', 'targetRevision' => 'b',
        ], ['username' => 'view', 'role' => 'viewer'])['status']);
    }

    public function testAuthorCanMarkOwnTargetButCannotMarkAnotherOwnersTarget(): void
    {
        $source = $this->content->draftPage('home', 'en');
        $target = $this->content->draftPage('home', 'de');
        $input = [
            'slug' => 'home', 'sourceLocale' => 'en', 'targetLocale' => 'de',
            'sourceRevision' => ContentRevision::of($source), 'targetRevision' => ContentRevision::of($target), 'expectedReviewedAt' => null,
        ];
        self::assertSame('reviewed', $this->controller->mark($input, ['username' => 'alice', 'role' => 'author'])['data']['freshness']);

        $input['slug'] = 'other';
        self::assertSame(403, $this->controller->mark($input, ['username' => 'alice', 'role' => 'author'])['status']);
    }

    public function testReadDoesNotClaimFreshnessForAnUnmarkedPairAndRejectsBadOrMissingLocales(): void
    {
        $base = ['slug' => 'home', 'sourceLocale' => 'en', 'targetLocale' => 'de'];
        self::assertSame('unknown', $this->controller->read($base, ['username' => 'reader', 'role' => 'viewer'])['data']['freshness']);
        self::assertSame(400, $this->controller->read(array_replace($base, ['targetLocale' => 'en']), ['username' => 'reader', 'role' => 'viewer'])['status']);
        self::assertSame(404, $this->controller->read(['slug' => 'home', 'sourceLocale' => 'en', 'targetLocale' => 'fr'], ['username' => 'reader', 'role' => 'viewer'])['status']);
    }

    public function testRoutesRemainPrivateAndWriteUsesTheExistingCsrfAndNoStoreGuards(): void
    {
        $api = new PagesController($this->base, $this->content);
        self::assertArrayHasKey('GET /api/translation-freshness', $api->routes());
        self::assertArrayHasKey('POST /api/translation-freshness', $api->routes());

        $sessionsDir = $this->base . '/data/sessions';
        mkdir($sessionsDir, 0o700, true);
        $id = bin2hex(random_bytes(32));
        file_put_contents($sessionsDir . '/' . $id . '.json', json_encode([
            'username' => 'alice', 'expiresAt' => time() + 3600, 'lastActivity' => time(),
            'csrfToken' => 'expected-token', 'user' => ['username' => 'alice', 'role' => 'author'],
        ]));
        $_COOKIE[SessionStore::COOKIE] = $id;
        $guard = new ApiGuard(new SessionStore($sessionsDir, 1800));
        self::assertFalse($guard->isPublic('translation-freshness', 'GET'));
        self::assertFalse($guard->isPublic('translation-freshness', 'POST'));
        self::assertSame(403, $guard->enforceCsrf('translation-freshness', 'POST', [])['status']);
        self::assertNull($guard->enforceCsrf('translation-freshness', 'POST', ['HTTP_X_CLICK_CSRF' => 'expected-token']));
        self::assertSame('no-store, private', (new SensitiveResponseCache($guard))->headersFor('/api/translation-freshness', 'GET')['Cache-Control']);
        unset($_COOKIE[SessionStore::COOKIE]);
    }
}
