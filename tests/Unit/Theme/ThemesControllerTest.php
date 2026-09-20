<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Theme;

use Click\Cms\Application\Theme\ThemeInstaller;
use Click\Cms\Application\Theme\ThemeRepository;
use Click\Cms\Http\ThemesController;
use PHPUnit\Framework\TestCase;

/**
 * Activating a theme changes what every visitor sees, so the interesting part of
 * this controller is who is allowed to do it. These pin the two gates — a
 * listing needs a session, a switch needs the settings capability — and that a
 * request naming a theme nobody installed is refused rather than stored.
 *
 * Upload is the same gate as activate: installing a design is a site-wide
 * change, so only an administrator may do it.
 */
final class ThemesControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-themes-api-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/themes', 0o775, true);
        mkdir($this->base . '/data/theme-uploads', 0o775, true);

        foreach (['default', 'dark'] as $id) {
            mkdir($this->base . '/themes/' . $id, 0o775, true);
            file_put_contents(
                $this->base . '/themes/' . $id . '/theme.json',
                json_encode(['name' => ucfirst($id), 'version' => '1.0.0'])
            );
            file_put_contents($this->base . '/themes/' . $id . '/theme.css', 'body{}');
        }

        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
        $this->rrmdir($this->base);
    }

    /** @param array<string, mixed> $user */
    private function controller(array $user): ThemesController
    {
        $themes = ThemeRepository::forInstallation($this->base);

        return new ThemesController(
            $themes,
            static fn (): array => $user,
            new ThemeInstaller(
                $this->base . '/themes',
                $this->base . '/data/theme-uploads',
                $themes,
            ),
        );
    }

    private function repository(): ThemeRepository
    {
        return ThemeRepository::forInstallation($this->base);
    }

    /**
     * @param array<string, string> $entries
     */
    private function makeZip(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    public function testTheRouteTableNamesTheEndpoints(): void
    {
        $routes = $this->controller(['role' => 'admin'])->routes();

        $this->assertArrayHasKey('GET /api/themes', $routes);
        $this->assertArrayHasKey('POST /api/themes/activate', $routes);
        $this->assertArrayHasKey('POST /api/themes/upload', $routes);
    }

    /* -------------------------------------------------------------- listing -- */

    public function testAnonymousCallersCannotListThemes(): void
    {
        $response = $this->controller([])->list();

        $this->assertSame(401, $response['status']);
        $this->assertSame('unauthenticated', $response['code']);
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
        $this->assertSame('forbidden', $response['code']);
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

    /* -------------------------------------------------------------- upload -- */

    public function testAnonymousCallersCannotUploadATheme(): void
    {
        $response = $this->controller([])->upload();

        $this->assertSame(401, $response['status']);
        $this->assertSame('unauthenticated', $response['code']);
    }

    public function testAnEditorCannotUploadATheme(): void
    {
        $response = $this->controller(['role' => 'editor'])->upload();

        $this->assertSame(403, $response['status']);
        $this->assertSame('forbidden', $response['code']);
    }

    public function testAnAdministratorUploadsAValidThemeZip(): void
    {
        $zip = $this->base . '/data/upload-me.zip';
        $this->makeZip($zip, [
            'coastal/theme.json' => json_encode([
                'name' => 'Coastal',
                'version' => '1.0.0',
                'description' => 'Sea air',
            ]),
            'coastal/theme.css' => "body { color: teal; }\n",
        ]);

        $_FILES['file'] = [
            'name' => 'coastal.zip',
            'type' => 'application/zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ];

        $response = $this->controller(['role' => 'admin'])->upload();

        $this->assertSame(201, $response['status']);
        $this->assertSame('coastal', $response['data']['id']);

        $list = $this->controller(['role' => 'admin'])->list();
        $ids = array_column($list['data']['themes'], 'id');
        $this->assertContains('coastal', $ids);
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
