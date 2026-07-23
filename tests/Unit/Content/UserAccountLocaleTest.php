<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * User accounts are not translated, so they must be keyed on one locale for both
 * reads and writes. The bug this pins: a site whose default language is not the
 * hard-coded fallback would seed (and create, and delete) an account under the
 * fallback locale while looking it up under the configured default — so the
 * admin could never log in and a created user vanished on the next request.
 * Routing every user key through {@see ContentService::userKey()} keeps them in
 * agreement.
 */
final class UserAccountLocaleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-userloc-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function service(string $default): ContentService
    {
        return new ContentService(new JsonStorage($this->dir), Locale::fromString($default));
    }

    public function testUserKeyUsesTheConfiguredDefaultLocaleNotTheFallback(): void
    {
        $this->assertSame('de', $this->service('de')->userKey('admin')->locale->code);
        $this->assertSame('fr', $this->service('fr')->userKey('admin')->locale->code);
    }

    public function testAnAccountWrittenAndReadThroughTheServiceRoundTrips(): void
    {
        $service = $this->service('de');

        $service->save(Content::create($service->userKey('admin'), ['username' => 'admin', 'role' => 'admin']));

        $found = $service->user('admin');
        $this->assertNotNull($found);
        $this->assertSame('admin', $found->data['username']);
    }

    public function testTheOldLocalelessKeyIsThePreciseBug(): void
    {
        // Writing the account the way the seed used to — ContentKey::user() with
        // no locale — puts it under the fallback locale. On a site whose default
        // is German, the lookup then misses it entirely. This is the regression
        // the fix removes.
        $service = $this->service('de');

        $service->save(Content::create(ContentKey::user('admin'), ['username' => 'admin']));

        $this->assertNull($service->user('admin'), 'A locale-less user key must not be found under a non-fallback default.');

        // Written through the service instead, it is found.
        $service->save(Content::create($service->userKey('admin'), ['username' => 'admin']));
        $this->assertNotNull($service->user('admin'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
