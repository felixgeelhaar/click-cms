<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Core\Application;
use Click\Cms\Application\Plugin\ContentRefusedException;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

/**
 * The content events as a real plugin actually receives them.
 *
 * The gate and the decorator are tested in isolation elsewhere. What is left —
 * and what decides whether anyone can build on this — is the wiring: that a
 * plugin dropped into `plugins/` and declaring these hooks in its `plugin.json`
 * is really reached, by every write path, with a payload it can act on, through
 * the whole decorator stack a booted kernel composes. A hook that is correct in
 * isolation and wired to nothing is documentation.
 *
 * Two plugins are installed, both active. One observes and logs; one throws on
 * every hook it declares, and sorts first so it is reached first. Every
 * assertion about the observer is therefore simultaneously an assertion that a
 * broken extension changed nothing.
 */
final class ContentEventsWiringTest extends TestCase
{
    /**
     * Built once for the whole class. A plugin bootstrap is `require_once`d into
     * the one PHP process, so a fresh directory per test would try to declare
     * the same class from a new path.
     */
    private static string $base = '';

    /**
     * What the observer saw during the very first boot of this installation,
     * captured before any test could clear the log. See
     * {@see testTheFirstBootAdminSeedIsObservedToo()} for why it is worth having.
     */
    private static string $firstBootLog = '';

    private Application $app;

    public static function setUpBeforeClass(): void
    {
        self::$base = sys_get_temp_dir() . '/click-cms-events-' . bin2hex(random_bytes(6));

        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir(self::$base . '/' . $dir, 0o775, true);
        }

        mkdir(self::$base . '/config/sections', 0o775, true);
        foreach (glob(dirname(__DIR__, 3) . '/config/sections/*.json') ?: [] as $type) {
            copy($type, self::$base . '/config/sections/' . basename($type));
        }

        file_put_contents(
            self::$base . '/config/core.json',
            json_encode(['core' => [
                // On, so the events are proven to fire outside the cache
                // decorator rather than only when it is absent.
                'cache' => ['enabled' => true],
                'languages' => ['default' => 'en', 'available' => ['en']],
            ]])
        );

        self::observerPlugin();
        self::brokenPlugin();

        // The first boot of an installation seeds the default admin account, and
        // it does so through the content service like everything else. Booting
        // here rather than letting whichever test ran first absorb it keeps that
        // one-time write observable instead of order-dependent.
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        (new Application(self::$base))->boot();
        self::$firstBootLog = (string) @file_get_contents(self::$base . '/data/events.log');
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$base);
    }

    /** Every hook this work adds, plus the two the publish gate already had. */
    private const HOOKS = [
        'content.before_save',
        'content.saved',
        'content.before_delete',
        'content.deleted',
        'content.unpublished',
        'content.before_publish',
        'content.published',
    ];

    private static function observerPlugin(): void
    {
        $dir = self::$base . '/plugins/observer';
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Observer',
            'description' => 'Records every content event it is given.',
            'version' => '1.0.0',
            'author' => 'test',
            'dependencies' => [],
            'hooks' => self::HOOKS,
        ]));

        // Refusal is driven by a file so one fixture can demonstrate both the
        // permitting and the refusing side of a veto.
        $methods = '';
        foreach (self::HOOKS as $hook) {
            $method = 'hook_' . str_replace('.', '_', $hook);
            $methods .= <<<PHP

                public function {$method}(array \$params) {
                    return \$this->observe('{$hook}', \$params);
                }
            PHP;
        }

        file_put_contents($dir . '/bootstrap.php', <<<PHP
        <?php
        class Plugin_observer {
            public function __construct(\$manager) {}
            public function activate(): bool { return true; }

            private function observe(string \$hook, array \$params) {
                \$line = json_encode(['hook' => \$hook] + \$params);
                file_put_contents(__DIR__ . '/../../data/events.log', \$line . "\\n", FILE_APPEND);

                \$refuse = __DIR__ . '/../../data/refuse-' . \$hook;
                if (file_exists(\$refuse)) {
                    return ['allowed' => false, 'reason' => trim((string) file_get_contents(\$refuse))];
                }

                return null;
            }
        {$methods}
        }
        PHP);
    }

    /**
     * Named so it sorts before the observer, and therefore runs first. A fixture
     * where the survivor went first would pass whatever the implementation did.
     */
    private static function brokenPlugin(): void
    {
        $dir = self::$base . '/plugins/breaker';
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Breaker',
            'description' => 'Throws on every hook it declares.',
            'version' => '1.0.0',
            'author' => 'test',
            'dependencies' => [],
            'hooks' => self::HOOKS,
        ]));

        $methods = '';
        foreach (self::HOOKS as $hook) {
            $method = 'hook_' . str_replace('.', '_', $hook);
            $methods .= <<<PHP

                public function {$method}(array \$params) {
                    throw new \\RuntimeException('{$hook} is broken');
                }
            PHP;
        }

        file_put_contents($dir . '/bootstrap.php', <<<PHP
        <?php
        class Plugin_breaker {
            public function __construct(\$manager) {}
            public function activate(): bool { return true; }
        {$methods}
        }
        PHP);
    }

    protected function setUp(): void
    {
        @unlink(self::$base . '/data/events.log');
        foreach (glob(self::$base . '/data/refuse-*') ?: [] as $flag) {
            @unlink($flag);
        }

        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application(self::$base);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        \Click\Cms\Application\Plugin\PublishGate::useAmbient(null);
    }

    /** Publishing goes through `PageService`, which is where the publish gate lives. */
    private function pages(): \Click\Cms\Application\Content\PageService
    {
        return new \Click\Cms\Application\Content\PageService(
            $this->app->getContentService(),
            new \Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository(self::$base . '/config/sections'),
        );
    }

    /** The kernel's own history service, which restores through the same storage stack. */
    private function history(): \Click\Cms\Application\History\HistoryService
    {
        return (new \ReflectionProperty($this->app, 'history'))->getValue($this->app);
    }

    private function refuse(string $hook, string $reason): void
    {
        file_put_contents(self::$base . '/data/refuse-' . $hook, $reason);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        $log = self::$base . '/data/events.log';
        if (!file_exists($log)) {
            return [];
        }

        $out = [];
        foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
            if ($line !== '') {
                $out[] = json_decode($line, true);
            }
        }

        return $out;
    }

    /**
     * The hooks seen, in order, as `hook key` strings.
     *
     * @param ?string $forKey Narrow to one document, so an unrelated write —
     *        boot seeding an account, say — cannot make an assertion about one
     *        page depend on what else the kernel did.
     * @return list<string>
     */
    private function observed(?string $forKey = null): array
    {
        $events = $forKey === null
            ? $this->events()
            : array_filter($this->events(), static fn (array $e): bool => ($e['key'] ?? null) === $forKey);

        return array_values(array_map(
            static fn (array $e): string => $e['hook'] . ' ' . ($e['key'] ?? '?'),
            $events
        ));
    }

    /* ------------------------------------------------------------ the proof -- */

    /**
     * One page through its whole life, and the events a plugin saw, in order.
     *
     * This is the test the feature exists for. Every operation is performed
     * through the services the admin API itself uses, on a kernel booted from
     * disk, with the throwing plugin dispatched ahead of the observer at every
     * step.
     */
    public function testAPageLifecycleIsObservedInOrder(): void
    {
        $content = $this->app->getContentService();
        $key = ContentKey::page('lifecycle');

        // Create.
        $content->save(Content::create($key, ['title' => 'Lifecycle']));
        // Change.
        $content->save(Content::create($key, ['title' => 'Lifecycle, revised']));
        // Publish — the pre-existing gate, reached through PageService.
        $pages = $this->pages();
        $pages->publish('lifecycle', ['username' => 'ada', 'role' => 'admin']);
        // Take down.
        $content->unpublish($key);
        // Remove.
        $content->delete($key);

        $this->assertSame([
            'content.before_save page:en:lifecycle',
            'content.saved page:en:lifecycle',
            'content.before_save page:en:lifecycle',
            'content.saved page:en:lifecycle',
            'content.before_publish page:en:lifecycle',
            'content.published page:en:lifecycle',
            'content.unpublished page:en:lifecycle',
            'content.before_delete page:en:lifecycle',
            'content.deleted page:en:lifecycle',
        ], $this->observed('page:en:lifecycle'));

        // …and every operation actually happened, with a plugin throwing on
        // every one of those hooks.
        $this->assertNull($content->draft($key), 'the page was really deleted');
    }

    /**
     * The argument for putting the firing points in storage, demonstrated by
     * accident and then kept on purpose.
     *
     * Nothing instrumented `AuthController::ensureDefaultAdminUser()` — it
     * predates the plugin hooks entirely and no handler was touched by this work
     * — and yet a plugin sees the account it seeds. That is what "no code path
     * can write without an event" buys, and no amount of remembering to fire
     * events in handlers would have produced it.
     */
    public function testTheFirstBootAdminSeedIsObservedToo(): void
    {
        $this->assertStringContainsString('content.saved', self::$firstBootLog);
        $this->assertStringContainsString('user:en:admin', self::$firstBootLog);
        // And the seeded password never left storage.
        $this->assertStringNotContainsString('passwordHash', self::$firstBootLog);
    }

    public function testTheFirstSaveIsReportedAsACreationAndTheSecondIsNot(): void
    {
        $content = $this->app->getContentService();
        $key = ContentKey::page('created-flag');

        $content->save(Content::create($key, ['title' => 'One']));
        $content->save(Content::create($key, ['title' => 'Two']));

        $saves = array_values(array_filter(
            $this->events(),
            static fn (array $e): bool => $e['hook'] === 'content.saved'
        ));

        $this->assertCount(2, $saves);
        $this->assertTrue($saves[0]['created']);
        $this->assertFalse($saves[1]['created']);
    }

    public function testAHistoryRestoreIsDistinguishableFromAnOrdinaryEdit(): void
    {
        $content = $this->app->getContentService();
        $key = ContentKey::page('restorable');

        $content->save(Content::create($key, ['title' => 'First']));
        $content->save(Content::create($key, ['title' => 'Second']));

        $admin = ['username' => 'ada', 'role' => 'admin'];
        $versions = $this->history()->all($key, $admin)['versions'] ?? [];
        $this->assertNotEmpty($versions, 'the fixture needs a version to restore');
        $this->history()->restore($key, (string) end($versions)['id'], $admin);

        $reasons = array_map(
            static fn (array $e): string => (string) ($e['reason'] ?? ''),
            array_values(array_filter(
                $this->events(),
                static fn (array $e): bool => $e['hook'] === 'content.saved'
            ))
        );

        $this->assertContains('restore', $reasons, 'a listener must be able to ignore a restore');
    }

    /**
     * The payload must not become the route by which every plugin learns every
     * password hash. Users are ordinary content documents, so this is not
     * hypothetical.
     */
    public function testNoSecretFromASavedUserReachesThePlugin(): void
    {
        $this->app->getContentService()->save(Content::create(ContentKey::user('ada'), [
            'username' => 'ada',
            'role' => 'admin',
            'email' => 'ada@example.com',
            'passwordHash' => '$2y$10$THISMUSTNEVERLEAVESTORAGE',
        ]));

        $raw = (string) file_get_contents(self::$base . '/data/events.log');

        $this->assertStringContainsString('user:en:ada', $raw, 'the event did fire');
        $this->assertStringNotContainsString('THISMUSTNEVERLEAVESTORAGE', $raw);
        $this->assertStringNotContainsString('passwordHash', $raw);
        $this->assertStringNotContainsString('ada@example.com', $raw);
    }

    /* ----------------------------------------------------------- the vetoes -- */

    public function testAPluginCanRefuseASaveAndNothingIsWritten(): void
    {
        $this->refuse('content.before_save', 'This page has no headline.');

        $content = $this->app->getContentService();
        $key = ContentKey::page('refused');

        try {
            $content->save(Content::create($key, ['title' => 'Refused']));
            $this->fail('a refused save must not return normally');
        } catch (ContentRefusedException $e) {
            $this->assertSame('This page has no headline.', $e->getMessage());
        }

        $this->assertNull($content->draft($key), 'nothing may reach disk');
        $this->assertNotContains('content.saved page:en:refused', $this->observed());
    }

    public function testAPluginCanRefuseADeleteAndTheDocumentSurvives(): void
    {
        $content = $this->app->getContentService();
        $key = ContentKey::page('protected');
        $content->save(Content::create($key, ['title' => 'Protected']));

        $this->refuse('content.before_delete', 'Three pages still link here.');

        try {
            $content->delete($key);
            $this->fail('a refused delete must not return normally');
        } catch (ContentRefusedException $e) {
            $this->assertSame('Three pages still link here.', $e->getMessage());
        }

        $this->assertNotNull($content->draft($key), 'the document must still be there');
    }

    /* --------------------------------------------------- the broken plugin -- */

    /**
     * The whole lifecycle again, with the observer removed from the picture: a
     * plugin that throws on every hook, and every operation still completing.
     */
    public function testAPluginThatThrowsOnEveryHookPreventsNothing(): void
    {
        // Make the observer refuse nothing and simply be another listener; the
        // subject here is Breaker, which throws on all seven hooks and is
        // dispatched first.
        $content = $this->app->getContentService();
        $key = ContentKey::page('survivor');

        $content->save(Content::create($key, ['title' => 'Survivor']));
        $this->assertNotNull($content->draft($key), 'the save landed');

        $pages = $this->pages();
        $published = $pages->publish('survivor', ['username' => 'ada', 'role' => 'admin']);
        $this->assertNull($published['error'], 'the publish was not blocked by the throwing plugin');
        $this->assertNotNull($content->get($key), 'the page really went live');

        $this->assertTrue($content->unpublish($key), 'the takedown happened');
        $this->assertTrue($content->delete($key), 'the delete happened');
        $this->assertNull($content->draft($key));

        // And the working plugin was still asked about every one of them, which
        // is the property isolation exists to protect.
        $this->assertSame([
            'content.before_save page:en:survivor',
            'content.saved page:en:survivor',
            'content.before_publish page:en:survivor',
            'content.published page:en:survivor',
            'content.unpublished page:en:survivor',
            'content.before_delete page:en:survivor',
            'content.deleted page:en:survivor',
        ], $this->observed('page:en:survivor'));
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            self::removeTree($path . '/' . $e);
        }

        @rmdir($path);
    }
}
