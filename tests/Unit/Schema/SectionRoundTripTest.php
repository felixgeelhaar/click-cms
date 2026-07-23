<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Schema;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the whole path a section takes: a declared type, editor input
 * validated against it, stored, and read back.
 *
 * Uses the starter types rather than invented ones, so it also proves the
 * shipped examples work end to end.
 */
final class SectionRoundTripTest extends TestCase
{
    private string $dir;
    private JsonStorage $storage;
    private JsonSectionTypeRepository $types;
    private SectionValidator $validator;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-roundtrip-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->storage = new JsonStorage($this->dir);
        $this->types = new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections');
        $this->validator = new SectionValidator();
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

    public function testAPageOfValidatedSectionsSurvivesStorage(): void
    {
        $cardGrid = $this->types->find('card-grid');
        $this->assertNotNull($cardGrid, 'starter type card-grid should exist');

        $result = $this->validator->validate($cardGrid, [
            'heading' => 'What we do',
            'columns' => '3',
            'cards' => [
                ['title' => 'First', 'body' => 'One'],
                ['title' => 'Second', 'body' => 'Two'],
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' ', $result->errors));

        $page = Content::create(ContentKey::page('home'), [
            'title' => 'Home',
            'status' => 'published',
            'sections' => [['type' => $cardGrid->id, 'values' => $result->values]],
        ]);

        $this->storage->save($page);
        $restored = $this->storage->find(ContentKey::page('home'));

        $this->assertNotNull($restored);

        $sections = $restored->data['sections'];
        $this->assertCount(1, $sections);
        $this->assertSame('card-grid', $sections[0]['type']);
        $this->assertSame('What we do', $sections[0]['values']['heading']);
        $this->assertCount(2, $sections[0]['values']['cards']);
        $this->assertSame('Second', $sections[0]['values']['cards'][1]['title']);
    }

    /**
     * The point of validating at the boundary: content can never hold a shape
     * the site's templates were not built for.
     */
    public function testUndeclaredKeysNeverReachStorage(): void
    {
        $type = $this->types->find('call-to-action');
        $this->assertNotNull($type);

        $result = $this->validator->validate($type, [
            'heading' => 'Get in touch',
            'buttonLabel' => 'Contact',
            'buttonUrl' => 'https://example.com/contact',
            'onclick' => 'alert(1)',
            'style' => 'position:fixed',
        ]);

        $this->assertTrue($result->isValid());

        $page = Content::create(ContentKey::page('cta'), [
            'sections' => [['type' => $type->id, 'values' => $result->values]],
        ]);
        $this->storage->save($page);

        $stored = $this->storage->find(ContentKey::page('cta'))->data['sections'][0]['values'];

        $this->assertArrayNotHasKey('onclick', $stored);
        $this->assertArrayNotHasKey('style', $stored);
        $this->assertSame('Get in touch', $stored['heading']);
    }

    public function testInvalidSectionIsRejectedBeforeItCanBeStored(): void
    {
        $type = $this->types->find('call-to-action');
        $this->assertNotNull($type);

        $result = $this->validator->validate($type, [
            'heading' => 'Missing the rest',
            'buttonUrl' => 'not-a-url',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('buttonLabel', $result->errors);
        $this->assertArrayHasKey('buttonUrl', $result->errors);
    }

    public function testSectionOrderIsPreserved(): void
    {
        $rich = $this->types->find('rich-text');
        $this->assertNotNull($rich);

        $sections = [];
        foreach (['One', 'Two', 'Three'] as $body) {
            $result = $this->validator->validate($rich, ['body' => $body]);
            $this->assertTrue($result->isValid());
            $sections[] = ['type' => $rich->id, 'values' => $result->values];
        }

        $this->storage->save(Content::create(ContentKey::page('ordered'), ['sections' => $sections]));

        $stored = $this->storage->find(ContentKey::page('ordered'))->data['sections'];
        $bodies = array_column(array_column($stored, 'values'), 'body');

        $this->assertSame(['One', 'Two', 'Three'], $bodies);
    }
}
