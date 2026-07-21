<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    public function testCreateExposesKeyTypeAndSlug(): void
    {
        $content = Content::create(ContentKey::page('home'), ['title' => 'Home']);

        $this->assertSame('page', $content->type());
        $this->assertSame('home', $content->slug());
        $this->assertSame('Home', $content->title());
    }

    public function testTitleFallsBackToSlugWhenMissingOrEmpty(): void
    {
        $this->assertSame('about-us', Content::create(ContentKey::page('about-us'))->title());
        $this->assertSame(
            'about-us',
            Content::create(ContentKey::page('about-us'), ['title' => ''])->title()
        );
    }

    public function testContentReturnsEmptyStringWhenAbsentOrNotAString(): void
    {
        $this->assertSame('', Content::create(ContentKey::page('a'))->content());
        $this->assertSame('', Content::create(ContentKey::page('a'), ['content' => 42])->content());
    }

    public function testUpdateMergesRatherThanReplaces(): void
    {
        $content = Content::create(ContentKey::page('home'), [
            'title' => 'Home',
            'content' => 'Body',
            'pluginField' => 'keep me',
        ]);

        $content->update(['title' => 'New Title']);

        $this->assertSame('New Title', $content->title());
        // A caller that knows nothing about these must not erase them.
        $this->assertSame('Body', $content->content());
        $this->assertSame('keep me', $content->data['pluginField']);
    }

    public function testUpdateWithNullRemovesTheField(): void
    {
        $content = Content::create(ContentKey::page('home'), ['subtitle' => 'gone soon']);

        $content->update(['subtitle' => null]);

        $this->assertArrayNotHasKey('subtitle', $content->data);
    }

    public function testUpdateAdvancesUpdatedAtButNotCreatedAt(): void
    {
        $content = Content::create(ContentKey::page('home'), [
            'createdAt' => '2020-01-01T00:00:00+00:00',
            'updatedAt' => '2020-01-01T00:00:00+00:00',
        ]);

        $createdBefore = $content->createdAt;
        $content->update(['title' => 'Changed']);

        $this->assertSame($createdBefore, $content->createdAt);
        $this->assertGreaterThan($content->createdAt, $content->updatedAt());
    }

    public function testUpdateCannotForgeTimestamps(): void
    {
        $content = Content::create(ContentKey::page('home'), [
            'createdAt' => '2020-01-01T00:00:00+00:00',
        ]);

        $content->update(['createdAt' => '1999-01-01T00:00:00+00:00']);

        $this->assertSame('2020-01-01', $content->createdAt->format('Y-m-d'));
        $this->assertArrayNotHasKey('createdAt', $content->data);
    }

    public function testStatusDefaultsToPublishedAndOnlyDraftOverrides(): void
    {
        $this->assertTrue(Content::create(ContentKey::page('a'))->isPublished());
        $this->assertFalse(
            Content::create(ContentKey::page('a'), ['status' => 'draft'])->isPublished()
        );
        // An unrecognised status must not accidentally hide a page.
        $this->assertTrue(
            Content::create(ContentKey::page('a'), ['status' => 'nonsense'])->isPublished()
        );
    }

    public function testRoundTripsThroughToArrayAndFromArray(): void
    {
        $original = Content::create(ContentKey::page('home'), [
            'title' => 'Home',
            'content' => 'Body',
            'nested' => ['a' => 1],
        ]);

        $restored = Content::fromArray($original->toArray());

        $this->assertSame($original->key->toString(), $restored->key->toString());
        $this->assertSame($original->data, $restored->data);
        $this->assertEquals(
            $original->createdAt->format(DATE_ATOM),
            $restored->createdAt->format(DATE_ATOM)
        );
    }

    public function testFromArrayRejectsRowWithoutKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Content::fromArray(['data' => []]);
    }

    public function testFromArrayRejectsNonArrayData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Content::fromArray(['key' => 'page:home', 'data' => 'not an array']);
    }

    public function testUnparseableStoredTimestampDoesNotThrow(): void
    {
        $content = Content::create(ContentKey::page('home'), ['createdAt' => 'not-a-date']);

        // Falls back to "now" rather than failing to load the page.
        $this->assertNotNull($content->createdAt);
    }
}
