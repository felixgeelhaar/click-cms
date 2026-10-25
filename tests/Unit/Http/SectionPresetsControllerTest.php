<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Identity\Role;
use Click\Cms\Core\Application;
use Click\Cms\Http\PagesController;
use Click\Cms\Http\SectionPresetsController;
use PHPUnit\Framework\TestCase;

final class SectionPresetsControllerTest extends TestCase
{
    private string $site;
    private SectionPresetsController $controller;

    protected function setUp(): void
    {
        $this->site = sys_get_temp_dir() . '/click-presets-' . bin2hex(random_bytes(6));
        mkdir($this->site . '/config/sections', 0700, true);
        mkdir($this->site . '/data', 0700, true);
        file_put_contents($this->site . '/config/sections/hero.json', json_encode([
            'id' => 'hero', 'label' => 'Hero', 'fields' => [
                ['name' => 'heading', 'type' => 'text', 'required' => true],
                ['name' => 'body', 'type' => 'richtext'],
            ],
        ]));
        file_put_contents($this->site . '/config/sections/empty.json', json_encode([
            'id' => 'empty', 'label' => 'Empty-safe', 'fields' => [
                ['name' => 'optional', 'type' => 'text'],
            ],
        ]));
        $this->controller = new SectionPresetsController($this->site, $this->site . '/config/sections');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->site);
    }

    public function testRequiresSectionEditorToReadAndPresetManagerToWrite(): void
    {
        self::assertSame(401, $this->controller->list([])['status']);
        self::assertSame(403, $this->controller->create(['username' => 'editor', 'role' => 'editor'], [
            'name' => 'Hero', 'sections' => [['type' => 'hero', 'values' => ['heading' => 'Welcome']]],
        ])['status']);
        self::assertSame(200, $this->controller->list(['username' => 'editor', 'role' => 'editor'])['status']);
    }

    public function testPrivatePresetEndpointsAreRegisteredInCoreManagementRoutes(): void
    {
        $routes = (new PagesController($this->site))->routes();
        self::assertArrayHasKey('GET /api/section-presets', $routes);
        self::assertArrayHasKey('GET /api/section-presets/:id/versions/:version', $routes);
        self::assertArrayHasKey('POST /api/section-presets', $routes);
        self::assertArrayHasKey('PUT /api/section-presets/:id', $routes);
        self::assertArrayHasKey('DELETE /api/section-presets/:id', $routes);
    }

    public function testKernelDispatchesPresetAndTranslationClosureRoutes(): void
    {
        $routes = (new PagesController($this->site))->routes();
        $app = new Application($this->site);

        // Route through the same matcher and dispatcher used by the HTTP kernel.
        // These routes are closures because they inject the session and query
        // state; invoking a method-array-only dispatcher used to turn them into
        // 500s before the controller could return its normal auth refusal.
        $dispatch = static function (string $path) use ($app, $routes): array {
            $match = (new \ReflectionMethod($app, 'matchRouteTable'))
                ->invoke($app, $routes, $path, 'GET');
            self::assertIsArray($match);

            return (new \ReflectionMethod($app, 'executeHandler'))
                ->invoke($app, $match['handler'], $match['params']);
        };

        self::assertSame(401, $dispatch('section-presets')['status']);
        self::assertSame(401, $dispatch('section-presets/preset-id/versions/3')['status']);
        self::assertSame(401, $dispatch('translation-freshness')['status']);
    }

    public function testShippedBusinessStarterAppearsWithoutSiteConfigPreset(): void
    {
        self::assertFileDoesNotExist($this->site . '/config/section-presets/business-site.json');
        $sectionTypes = dirname(__DIR__, 3) . '/config/sections';
        $controller = new SectionPresetsController($this->site, $sectionTypes);
        $result = $controller->list(['username' => 'editor', 'role' => 'editor']);
        self::assertSame(200, $result['status']);
        $starter = array_values(array_filter($result['data'], static fn (array $preset): bool => $preset['id'] === 'starter-business-site'))[0] ?? null;
        self::assertIsArray($starter);
        self::assertSame('Business services site', $starter['name']);
        self::assertFalse($starter['editable']);
        self::assertTrue($starter['compatible']);
        self::assertSame(1, $starter['currentVersion']);
        self::assertSame('section-heading', $starter['sections'][0]['type']);
        self::assertSame(200, $controller->version('starter-business-site', 1, ['username' => 'editor', 'role' => 'editor'])['status']);
        self::assertSame(409, $controller->create(['username' => 'admin', 'role' => 'admin'], [
            'name' => 'Business services site',
            'sections' => [['type' => 'section-heading', 'values' => ['heading' => 'A new heading']]],
        ])['status']);
        self::assertSame(404, $controller->update('starter-business-site', ['username' => 'admin', 'role' => 'admin'], [
            'expectedVersion' => 1,
            'name' => 'Changed starter',
            'sections' => [['type' => 'section-heading', 'values' => ['heading' => 'Changed']]],
        ])['status']);
    }

    public function testUpgradePackageProvidesStarterWithoutShippingSiteConfiguration(): void
    {
        // Model the release's src-only layout, with no config directory beside
        // it. Run in a fresh process so this copied controller is the one loaded.
        $package = $this->site . '/release';
        mkdir($package . '/src/Http', 0700, true);
        mkdir($package . '/src/Resources/section-presets', 0700, true);
        $root = dirname(__DIR__, 3);
        copy($root . '/src/Http/SectionPresetsController.php', $package . '/src/Http/SectionPresetsController.php');
        copy($root . '/src/Resources/section-presets/business-site.json', $package . '/src/Resources/section-presets/business-site.json');
        $script = '<?php require ' . var_export($root . '/vendor/autoload.php', true)
            . '; require ' . var_export($package . '/src/Http/SectionPresetsController.php', true)
            . '; $controller = new \Click\Cms\Http\SectionPresetsController('
            . var_export($this->site, true) . ', ' . var_export($root . '/config/sections', true)
            . '); echo json_encode($controller->list(["username"=>"editor", "role"=>"editor"]));';
        file_put_contents($this->site . '/check-package.php', $script);
        $process = proc_open([PHP_BINARY, $this->site . '/check-package.php'], [
            1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $result['status']);
        self::assertContains('starter-business-site', array_column($result['data'], 'id'));
    }

    public function testCreatesImmutableVersionsAndRejectsStaleUpdates(): void
    {
        $admin = ['username' => 'admin', 'role' => 'admin'];
        $created = $this->controller->create($admin, [
            'name' => 'Homepage hero', 'sections' => [['type' => 'hero', 'values' => ['heading' => 'Welcome']]],
        ]);
        self::assertSame(201, $created['status']);
        $id = $created['data']['id'];
        self::assertSame(1, $created['data']['currentVersion']);

        $updated = $this->controller->update($id, $admin, [
            'expectedVersion' => 1,
            'name' => 'Homepage hero',
            'sections' => [['type' => 'hero', 'values' => ['heading' => 'Welcome back']]],
        ]);
        self::assertSame(200, $updated['status']);
        self::assertSame(2, $updated['data']['currentVersion']);
        self::assertSame(409, $this->controller->update($id, $admin, [
            'expectedVersion' => 1,
            'name' => 'Homepage hero',
            'sections' => [['type' => 'hero', 'values' => ['heading' => 'Overwrite']]],
        ])['status']);

        $old = $this->controller->version($id, 1, ['username' => 'editor', 'role' => 'editor']);
        self::assertSame('Welcome', $old['data']['sections'][0]['values']['heading']);
        $records = array_values(array_filter($this->controller->list($admin)['data'], static fn (array $record): bool => $record['id'] === $id));
        self::assertSame('Welcome back', $records[0]['sections'][0]['values']['heading']);
    }

    public function testRejectsUndeclaredTypesAndInvalidValuesWithoutWriting(): void
    {
        $admin = ['username' => 'admin', 'role' => 'admin'];
        $unknown = $this->controller->create($admin, [
            'name' => 'Bad type', 'sections' => [['type' => 'arbitrary', 'values' => ['heading' => 'x']]],
        ]);
        self::assertSame(422, $unknown['status']);
        $invalid = $this->controller->create($admin, [
            'name' => 'Missing required', 'sections' => [['type' => 'hero', 'values' => []]],
        ]);
        self::assertSame(422, $invalid['status']);
        self::assertSame([], array_values(array_filter($this->controller->list($admin)['data'], static fn (array $record): bool => $record['id'] !== 'starter-business-site')));
    }

    public function testDeleteUsesVersionPrecondition(): void
    {
        $admin = ['username' => 'admin', 'role' => 'admin'];
        $created = $this->controller->create($admin, [
            'name' => 'Hero', 'sections' => [['type' => 'hero', 'values' => ['heading' => 'Welcome']]],
        ]);
        $id = $created['data']['id'];
        self::assertSame(409, $this->controller->delete($id, $admin, 4)['status']);
        self::assertSame(200, $this->controller->delete($id, $admin, 1)['status']);
        self::assertSame([], array_values(array_filter($this->controller->list($admin)['data'], static fn (array $record): bool => $record['id'] !== 'starter-business-site')));
    }

    public function testCorruptPresetStoreFailsClosedWithoutReplacingTheFile(): void
    {
        $broken = '{truncated';
        file_put_contents($this->site . '/data/section-presets.json', $broken);
        $result = $this->controller->create(['username' => 'admin', 'role' => 'admin'], [
            'name' => 'Hero', 'sections' => [['type' => 'hero', 'values' => ['heading' => 'Welcome']]],
        ]);
        self::assertSame(500, $result['status']);
        self::assertSame($broken, file_get_contents($this->site . '/data/section-presets.json'));
    }

    public function testOldVersionsAreCappedAtFiftyWithoutMutatingRetainedSnapshots(): void
    {
        $admin = ['username' => 'admin', 'role' => 'admin'];
        $created = $this->controller->create($admin, [
            'name' => 'Hero', 'sections' => [['type' => 'hero', 'values' => ['heading' => 'Version 1']]],
        ]);
        $id = $created['data']['id'];
        for ($version = 1; $version <= 51; $version++) {
            $updated = $this->controller->update($id, $admin, [
                'expectedVersion' => $version,
                'name' => 'Hero',
                'sections' => [['type' => 'hero', 'values' => ['heading' => "Version " . ($version + 1)]]],
            ]);
            self::assertSame($version + 1, $updated['data']['currentVersion']);
        }
        $records = array_values(array_filter($this->controller->list($admin)['data'], static fn (array $record): bool => $record['id'] === $id));
        self::assertCount(50, $records[0]['versions']);
        self::assertSame(404, $this->controller->version($id, 2, $admin)['status']);
        self::assertSame('Version 3', $this->controller->version($id, 3, $admin)['data']['sections'][0]['values']['heading']);
    }

    public function testOptionalOnlySectionRoundTripsWithEmptyValues(): void
    {
        $admin = ['username' => 'admin', 'role' => 'admin'];
        $created = $this->controller->create($admin, [
            'name' => 'Empty safe', 'sections' => [['type' => 'empty', 'values' => []]],
        ]);
        self::assertSame(201, $created['status']);
        $records = array_values(array_filter($this->controller->list($admin)['data'], static fn (array $record): bool => $record['name'] === 'Empty safe'));
        self::assertSame([], $records[0]['sections'][0]['values']);
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        @rmdir($path);
    }
}
