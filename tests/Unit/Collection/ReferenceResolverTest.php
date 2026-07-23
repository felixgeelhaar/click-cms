<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Collection;

use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * A reference stores a target's slug; resolution turns it into a title a client
 * can show. The behaviours that matter: a live target resolves to its title
 * through the collection type's title field; a target that no longer exists
 * resolves to exists:false with the slug standing in (a dangling link is a fact
 * to surface, not an error); and delivery resolves published targets only, so a
 * public reference never reveals an unpublished item.
 */
final class ReferenceResolverTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private ReferenceResolver $resolver;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-refs-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/collections', 0o700, true);
        file_put_contents($this->base . '/collections/team-member.json', json_encode([
            'label' => 'Team members',
            'titleField' => 'name',
            'fields' => [['name' => 'name', 'type' => 'text', 'required' => true]],
        ]));

        Publishable::register(['team-member']);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/versions'),
        );
        $this->content = new ContentService($storage);
        $this->resolver = new ReferenceResolver(
            $this->content,
            new JsonCollectionTypeRepository($this->base . '/collections'),
        );
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        $this->rrmdir($this->base);
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

    public function testResolvesACollectionReferenceToItsTitle(): void
    {
        $key = ContentKey::for('team-member', 'ada');
        $this->content->save(Content::create($key, ['name' => 'Ada Lovelace', 'slug' => 'ada']));
        $this->content->publish($key);

        $r = $this->resolver->resolve('team-member', 'ada', null, true);
        $this->assertSame('team-member', $r['type']);
        $this->assertSame('ada', $r['slug']);
        $this->assertSame('Ada Lovelace', $r['title']);
        $this->assertTrue($r['exists']);
    }

    public function testADanglingReferenceResolvesToExistsFalseWithoutThrowing(): void
    {
        $r = $this->resolver->resolve('team-member', 'ghost', null, true);
        $this->assertFalse($r['exists']);
        $this->assertSame('ghost', $r['title']);
    }

    public function testDeliveryDoesNotResolveAnUnpublishedTarget(): void
    {
        // A working copy only — never published.
        $this->content->save(Content::create(ContentKey::for('team-member', 'grace'), ['name' => 'Grace Hopper', 'slug' => 'grace']));

        // The editor (working copies) sees the title...
        $editor = $this->resolver->resolve('team-member', 'grace', null, false);
        $this->assertTrue($editor['exists']);
        $this->assertSame('Grace Hopper', $editor['title']);

        // ...but delivery (published only) does not.
        $delivery = $this->resolver->resolve('team-member', 'grace', null, true);
        $this->assertFalse($delivery['exists']);
    }
}
