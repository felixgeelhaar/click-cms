<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\ContentRevision;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\TestCase;

final class ContentRevisionTest extends TestCase
{
    public function testRevisionIsStableAcrossAssociativeKeyOrderAndPreservesListOrder(): void
    {
        $one = Content::create(ContentKey::page('home'), ['metadata' => ['a' => 1, 'b' => 2], 'items' => ['a', 'b']]);
        $two = Content::fromArray([
            'key' => 'page:home',
            'createdAt' => $one->createdAt->format(DATE_ATOM),
            'updatedAt' => $one->updatedAt()->format(DATE_ATOM),
            'data' => ['items' => ['a', 'b'], 'metadata' => ['b' => 2, 'a' => 1]],
        ]);
        $three = Content::fromArray([
            'key' => 'page:home',
            'createdAt' => $one->createdAt->format(DATE_ATOM),
            'updatedAt' => $one->updatedAt()->format(DATE_ATOM),
            'data' => ['items' => ['b', 'a'], 'metadata' => ['b' => 2, 'a' => 1]],
        ]);

        $this->assertSame(ContentRevision::of($one), ContentRevision::of($two));
        $this->assertNotSame(ContentRevision::of($one), ContentRevision::of($three));
    }
}
