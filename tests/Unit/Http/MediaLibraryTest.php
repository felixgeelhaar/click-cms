<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Media\MediaItem;
use Click\Cms\Http\MediaLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Search, folder grouping and bulk deletion over the media library.
 *
 * These exercise MediaLibrary directly against a real {@see MediaService} backed
 * by a temp directory — the reading and deleting are genuinely delegated, so the
 * test proves the whole path rather than a mock of it — and against a stubbed
 * role resolver, so the authorization gate can be checked one role at a time.
 */
final class MediaLibraryTest extends TestCase
{
    private string $base;
    private MediaService $media;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-medialib-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o775, true);
        $this->media = new MediaService($this->base);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /**
     * Seed a media record straight to disk. The library only reads the stored
     * JSON, so a real upload (which needs GD and an actual image) is unnecessary
     * to exercise listing and deletion — the metadata is the whole input.
     */
    private function seed(string $id, string $originalName): void
    {
        $item = MediaItem::create(
            id: $id,
            extension: 'jpg',
            mimeType: 'image/jpeg',
            originalName: $originalName,
            bytes: 1024,
            width: 800,
            height: 600,
        );

        file_put_contents(
            $this->base . '/' . $id . '.json',
            json_encode($item->toArray(), JSON_UNESCAPED_SLASHES),
        );
        // A stand-in for the served original, so a delete has a file to remove.
        file_put_contents($this->base . '/' . $id . '.jpg', 'not-a-real-image');
    }

    private function library(Role $role = Role::Admin): MediaLibrary
    {
        return new MediaLibrary($this->media, static fn (): Role => $role);
    }

    /** @return list<string> ids in the listing, in order */
    private function idsOf(array $result): array
    {
        return array_map(static fn (array $row): string => $row['id'], $result['data']);
    }

    public function testSearchFiltersByFilename(): void
    {
        $this->seed('harbour-crane-aaaa', 'Harbour Crane.jpg');
        $this->seed('city-skyline-bbbb', 'City Skyline.jpg');
        $this->seed('night-harbour-cccc', 'Night Harbour.jpg');

        $result = $this->library()->list(['q' => 'harbour']);

        $ids = $this->idsOf($result);
        sort($ids);
        self::assertSame(['harbour-crane-aaaa', 'night-harbour-cccc'], $ids);
        self::assertSame(2, $result['total']);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $this->seed('logo-aaaa', 'BrandLogo.svg is not — Logo.jpg');
        $this->seed('other-bbbb', 'Photo.jpg');

        $result = $this->library()->list(['q' => 'logo']);

        self::assertSame(['logo-aaaa'], $this->idsOf($result));
    }

    public function testFolderFilterNarrowsResults(): void
    {
        $this->seed('hero-aaaa', 'products/hero.jpg');
        $this->seed('thumb-bbbb', 'products/thumb.jpg');
        $this->seed('banner-cccc', 'marketing/banner.jpg');
        $this->seed('loose-dddd', 'loose.jpg');

        $result = $this->library()->list(['folder' => 'products']);

        $ids = $this->idsOf($result);
        sort($ids);
        self::assertSame(['hero-aaaa', 'thumb-bbbb'], $ids);
    }

    public function testRootFolderFilterReturnsOnlyUngroupedItems(): void
    {
        $this->seed('hero-aaaa', 'products/hero.jpg');
        $this->seed('loose-dddd', 'loose.jpg');

        $result = $this->library()->list(['folder' => '']);

        self::assertSame(['loose-dddd'], $this->idsOf($result));
    }

    public function testListReportsDistinctSortedFolders(): void
    {
        $this->seed('hero-aaaa', 'products/hero.jpg');
        $this->seed('thumb-bbbb', 'products/thumb.jpg');
        $this->seed('banner-cccc', 'marketing/banner.jpg');
        $this->seed('loose-dddd', 'loose.jpg');

        $result = $this->library()->list([]);

        // Root ("") plus each distinct prefix, sorted, and no duplicate for the
        // two products items.
        self::assertSame(['', 'marketing', 'products'], $result['folders']);
    }

    public function testEachListedItemCarriesItsFolder(): void
    {
        $this->seed('hero-aaaa', 'products/hero.jpg');

        $result = $this->library()->list(['folder' => 'products']);

        self::assertSame('products', $result['data'][0]['folder']);
    }

    public function testFoldersListIsWholeLibraryEvenWhenFiltered(): void
    {
        $this->seed('hero-aaaa', 'products/hero.jpg');
        $this->seed('banner-cccc', 'marketing/banner.jpg');

        // Filtering the view to one folder must not shrink the folder chooser.
        $result = $this->library()->list(['folder' => 'products']);

        self::assertSame(['marketing', 'products'], $result['folders']);
        self::assertSame(['hero-aaaa'], $this->idsOf($result));
    }

    public function testBulkDeleteRemovesExactlyTheGivenIdsAndReportsEach(): void
    {
        $this->seed('keep-aaaa', 'keep.jpg');
        $this->seed('drop-one-bbbb', 'one.jpg');
        $this->seed('drop-two-cccc', 'two.jpg');

        $result = $this->library()->bulkDelete(['drop-one-bbbb', 'drop-two-cccc', 'never-existed']);

        // The two real ids are gone; the untouched one survives.
        self::assertNull($this->media->find('drop-one-bbbb'));
        self::assertNull($this->media->find('drop-two-cccc'));
        self::assertNotNull($this->media->find('keep-aaaa'));

        // Every requested id is reported with its own outcome.
        self::assertSame(3, $result['data']['requested']);
        self::assertSame(2, $result['data']['deleted']);
        self::assertSame(
            [
                ['id' => 'drop-one-bbbb', 'deleted' => true],
                ['id' => 'drop-two-cccc', 'deleted' => true],
                ['id' => 'never-existed', 'deleted' => false],
            ],
            $result['data']['results'],
        );
    }

    public function testBulkDeleteDeduplicatesRepeatedIds(): void
    {
        $this->seed('drop-aaaa', 'one.jpg');

        $result = $this->library()->bulkDelete(['drop-aaaa', 'drop-aaaa']);

        // A repeated id must not be counted twice, nor reported twice.
        self::assertSame(1, $result['data']['requested']);
        self::assertSame(1, $result['data']['deleted']);
        self::assertCount(1, $result['data']['results']);
    }

    public function testBulkDeleteRejectsAnEmptyRequest(): void
    {
        $result = $this->library()->bulkDelete(['', '']);

        self::assertSame(400, $result['status']);
    }

    public function testCallerWithoutManagementCapabilityIsRefused(): void
    {
        $this->seed('drop-aaaa', 'one.jpg');

        // An editor may view and upload media but not delete any — the exact gap
        // the DeleteAnyMedia capability marks.
        $result = $this->library(Role::Editor)->bulkDelete(['drop-aaaa']);

        self::assertSame(403, $result['status']);
        // Refused whole: the file is still there.
        self::assertNotNull($this->media->find('drop-aaaa'));
    }

    public function testAdminMayBulkDelete(): void
    {
        $this->seed('drop-aaaa', 'one.jpg');

        $result = $this->library(Role::Admin)->bulkDelete(['drop-aaaa']);

        self::assertArrayNotHasKey('status', $result);
        self::assertSame(1, $result['data']['deleted']);
    }
}
