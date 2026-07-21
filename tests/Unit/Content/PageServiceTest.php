<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

final class PageServiceTest extends TestCase
{
    private string $dir;
    private PageService $service;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-pages-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->service = new PageService(
            new ContentService(new JsonStorage($this->dir)),
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return ['username' => 'boss', 'role' => 'admin'];
    }

    public function testCreatesAPageAndDerivesItsSlug(): void
    {
        $result = $this->service->create(['title' => 'About Us'], $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('about-us', $result['page']->slug());
    }

    public function testAnExplicitSlugWins(): void
    {
        $result = $this->service->create(['title' => 'About Us', 'slug' => 'company'], $this->admin());

        $this->assertSame('company', $result['page']->slug());
    }

    public function testRefusesAnEmptyPayload(): void
    {
        $result = $this->service->create([], $this->admin());

        $this->assertSame(400, $result['status']);
    }

    public function testRefusesADuplicateAddress(): void
    {
        $this->service->create(['title' => 'Home', 'slug' => 'home'], $this->admin());
        $result = $this->service->create(['title' => 'Home again', 'slug' => 'home'], $this->admin());

        $this->assertSame(409, $result['status']);
    }

    public function testRecordsTheCreatorAsOwner(): void
    {
        $result = $this->service->create(['title' => 'Mine'], $this->admin());

        $this->assertSame('boss', $result['page']->data['owner']);
    }

    public function testValidSectionsAreStoredNormalised(): void
    {
        $result = $this->service->create([
            'title' => 'Landing',
            'sections' => [[
                'type' => 'call-to-action',
                'values' => [
                    'heading' => 'Talk to us',
                    'buttonLabel' => 'Contact',
                    'buttonUrl' => 'https://example.com',
                    'smuggled' => 'should not survive',
                ],
            ]],
        ], $this->admin());

        $this->assertNull($result['error']);
        $values = $result['page']->data['sections'][0]['values'];
        $this->assertSame('Talk to us', $values['heading']);
        $this->assertArrayNotHasKey('smuggled', $values);
    }

    public function testInvalidSectionsAreRefusedWithPerFieldErrors(): void
    {
        $result = $this->service->create([
            'title' => 'Landing',
            'sections' => [[
                'type' => 'call-to-action',
                'values' => ['heading' => 'Only this'],
            ]],
        ], $this->admin());

        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('0.buttonLabel', $result['errors']);
    }

    public function testUnknownSectionTypeIsRefused(): void
    {
        $result = $this->service->create([
            'title' => 'Landing',
            'sections' => [['type' => 'not-a-real-design', 'values' => []]],
        ], $this->admin());

        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('0.type', $result['errors']);
    }

    public function testUpdateMergesAndCannotChangeTheAddress(): void
    {
        $this->service->create(['title' => 'Home', 'slug' => 'home', 'status' => 'draft'], $this->admin());

        $result = $this->service->update('home', ['title' => 'Renamed', 'slug' => 'somewhere-else'], $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame('home', $result['page']->slug());
        $this->assertSame('Renamed', $result['page']->title());
        // Untouched fields survive.
        $this->assertSame('draft', $result['page']->data['status']);
    }

    public function testUpdatingAMissingPageIsNotFound(): void
    {
        $this->assertSame(404, $this->service->update('ghost', ['title' => 'x'], $this->admin())['status']);
    }

    public function testDeleteRemovesThePage(): void
    {
        $this->service->create(['title' => 'Temp', 'slug' => 'temp'], $this->admin());

        $this->assertNull($this->service->delete('temp', $this->admin())['error']);
        $this->assertNull($this->service->find('temp'));
    }

    /* -------------------------------------------------------- permissions -- */

    public function testAdminsAndEditorsMayEditAnyPage(): void
    {
        $page = ['owner' => 'someone-else'];

        $this->assertTrue($this->service->canModify($page, ['username' => 'a', 'role' => 'admin']));
        $this->assertTrue($this->service->canModify($page, ['username' => 'b', 'role' => 'editor']));
    }

    public function testAuthorsMayEditOnlyTheirOwnPages(): void
    {
        $mine = ['owner' => 'ann'];
        $theirs = ['owner' => 'bob'];
        $author = ['username' => 'ann', 'role' => 'author'];

        $this->assertTrue($this->service->canModify($mine, $author));
        $this->assertIsString($this->service->canModify($theirs, $author));
    }

    public function testAnUnknownRoleMayNotEdit(): void
    {
        $this->assertIsString(
            $this->service->canModify(['owner' => 'x'], ['username' => 'x', 'role' => 'visitor'])
        );
    }

    /**
     * Deleting cannot be partially undone, so it is held to a stricter rule
     * than editing: an editor may change anyone's page but only remove their own.
     */
    public function testDeletionIsStricterThanEditing(): void
    {
        $theirs = ['owner' => 'bob'];
        $editor = ['username' => 'ann', 'role' => 'editor'];

        $this->assertTrue($this->service->canModify($theirs, $editor));
        $this->assertIsString($this->service->canDelete($theirs, $editor));
        $this->assertTrue($this->service->canDelete($theirs, ['username' => 'boss', 'role' => 'admin']));
    }

    public function testAuthorWithoutOwnerRecordedCannotEdit(): void
    {
        $this->assertIsString(
            $this->service->canModify([], ['username' => 'ann', 'role' => 'author'])
        );
    }
}
