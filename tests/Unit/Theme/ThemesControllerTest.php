<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Theme;

use Click\Cms\Application\Theme\ThemeRepository;
use Click\Cms\Http\ThemesController;
use PHPUnit\Framework\TestCase;

/**
 * Activating a theme changes what every visitor sees, so the interesting part of
 * this controller is who is allowed to do it. These pin the two gates — a
 * listing needs a session, a switch needs the settings capability — and that a
 * request naming a theme nobody installed is refused rather than stored.
 */
final class ThemesControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-themes-api-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/themes', 0o775, true);

        foreach (['default', 'dark'] as $id) {
            mkdir($this->base . '/themes/' . $id, 0o775, true);
            file_put_contents(
                $this->base . '/themes/' . $id . '/theme.json',
                json_encode(['name' => ucfirst($id), 'version' => '1.0.0'])
            );
            file_put_contents($this->base . '/themes/' . $id . '/theme.css', 'body{}');
        }

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $this->rrmdir($this->base);
    }

    /** @param array<string, mixed> $user */
    private function controller(array $user): ThemesController
    {
        return new ThemesController(
            ThemeRepository::forInstallation($this->base),
            static fn (): array => $user
        );
    }

    private function repository(): ThemeRepository
    {
        return ThemeRepository::forInstallation($this->base);
    }

    public function testTheRouteTableNamesTheTwoEndpoints(): void
    {
        $routes = $this->controller(['role' => 'admin'])->routes();

        $this->assertArrayHasKey('GET /api/themes', $routes);
        $this->assertArrayHasKey('POST /api/themes/activate', $routes);
    }

    /* -------------------------------------------------------------- listing -- */

    public function testAnonymousCallersCannotListThemes(): void
    {
        $response = $this->controller([])->list();

        $this->assertSame(401, $response['status']);
    }

    public function testAnySignedInAccountMaySeeWhichThemeIsLive(): void
    {
        // An editor seeing the theme list is not a risk, and hiding it would
        // only make the admin screen look broken to everyone but an admin.
        $response = $this->controller(['role' => 'editor'])->list();

        $this->assertArrayNotHasKey('status', $response);
        $this->assertSame('default', $response['data']['active']);
        $this->assertSame(['dark', 'default'], array_column($response['data']['themes'], 'id'));
    }

    public function testTheListingMarksTheActiveThemeAndCarriesTheUrlAPageWouldLink(): void
    {
        $this->repository()->activate('dark');

        $response = $this->controller(['role' => 'admin'])->list();
        $themes = array_column($response['data']['themes'], null, 'id');

        $this->assertTrue($themes['dark']['active']);
        $this->assertFalse($themes['default']['active']);
        $this->assertStringStartsWith('/themes/dark/theme.css?v=', $themes['dark']['stylesheetUrl']);
    }

    /* ----------------------------------------------------------- activating -- */

    public function testAnonymousCallersCannotSwitchTheTheme(): void
    {
        $_POST = ['id' => 'dark'];

        $this->assertSame(401, $this->controller([])->activate()['status']);
        $this->assertSame('default', $this->repository()->active()?->id);
    }

    public function testAnEditorCannotSwitchTheTheme(): void
    {
        // Redesigning the whole public site is a site-wide switch, not an
        // editorial act, so it sits behind the same capability as the others.
        $_POST = ['id' => 'dark'];

        $response = $this->controller(['role' => 'editor'])->activate();

        $this->assertSame(403, $response['status']);
        $this->assertSame('default', $this->repository()->active()?->id);
    }

    public function testAnAdministratorSwitchesTheTheme(): void
    {
        $_POST = ['id' => 'dark'];

        $response = $this->controller(['role' => 'admin'])->activate();

        $this->assertArrayNotHasKey('status', $response);
        $this->assertSame('dark', $response['data']['active']);
        $this->assertSame('dark', $this->repository()->active()?->id);
    }

    public function testActivatingWithNoThemeNamedIsRefused(): void
    {
        $response = $this->controller(['role' => 'admin'])->activate();

        $this->assertSame(400, $response['status']);
    }

    public function testActivatingAThemeThatIsNotInstalledIsNotFound(): void
    {
        $_POST = ['id' => 'sunset'];

        $response = $this->controller(['role' => 'admin'])->activate();

        $this->assertSame(404, $response['status']);
        $this->assertSame('default', $this->repository()->active()?->id);
    }

    /* ------------------------------------------------------------- helpers -- */

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
