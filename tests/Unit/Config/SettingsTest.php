<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Config;

use Click\Cms\Application\Config\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Runtime, operator-editable settings.
 *
 * These are distinct from the bootstrap config in `config/core.json`, which is
 * baked into the image and cannot be changed from a running site. Settings live
 * in `data/`, which is the writable, persisted directory, precisely so they can
 * be turned on and off from the admin UI and survive a redeploy — the thing
 * config-in-the-image cannot do.
 */
final class SettingsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/click-cms-settings-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testHeadlessIsOffByDefault(): void
    {
        // A fresh install renders its own site: the switch is a decision an
        // operator opts into, not the default anyone inherits.
        $settings = Settings::load($this->path);

        $this->assertFalse($settings->headless());
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $this->assertFalse(is_file($this->path));

        $settings = Settings::load($this->path);

        $this->assertFalse($settings->headless());
    }

    public function testTurningHeadlessOnPersists(): void
    {
        Settings::load($this->path)->setHeadless(true);

        // A separate load, so this proves it reached disk rather than living in
        // one object's memory.
        $this->assertTrue(Settings::load($this->path)->headless());
    }

    public function testTurningHeadlessBackOffPersists(): void
    {
        Settings::load($this->path)->setHeadless(true);
        Settings::load($this->path)->setHeadless(false);

        $this->assertFalse(Settings::load($this->path)->headless());
    }

    public function testACorruptFileReadsAsDefaultsRatherThanThrowing(): void
    {
        file_put_contents($this->path, '{ this is not json');

        // A settings file a stray write corrupted must not take the whole site
        // down — the safe reading is "no operator settings", i.e. defaults.
        $settings = Settings::load($this->path);

        $this->assertFalse($settings->headless());
    }

    public function testWritingCreatesThePathIfTheDirectoryExists(): void
    {
        $this->assertFalse(is_file($this->path));

        Settings::load($this->path)->setHeadless(true);

        $this->assertTrue(is_file($this->path));
    }

    public function testTheStateIsReadableAsAnArrayForAnApiResponse(): void
    {
        $settings = Settings::load($this->path);
        $settings->setHeadless(true);

        $this->assertSame(
            ['headless' => true, 'siteName' => ''],
            Settings::load($this->path)->toArray()
        );
    }

    public function testSiteNameIsEmptyByDefault(): void
    {
        // A fresh install has no brand until an operator names the site; the
        // header simply shows no brand rather than inventing one.
        $this->assertSame('', Settings::load($this->path)->siteName());
    }

    public function testSettingTheSiteNamePersistsAndTrims(): void
    {
        Settings::load($this->path)->setSiteName('  Acme Studio  ');

        // Trimmed on the way in, so the stored brand has no stray whitespace, and
        // it reached disk rather than one object's memory.
        $this->assertSame('Acme Studio', Settings::load($this->path)->siteName());
    }

    public function testClearingTheSiteNamePersists(): void
    {
        Settings::load($this->path)->setSiteName('Acme Studio');
        Settings::load($this->path)->setSiteName('');

        $this->assertSame('', Settings::load($this->path)->siteName());
    }
}
