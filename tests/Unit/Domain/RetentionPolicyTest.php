<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\History\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function testKeepsEverythingWhileUnderTheLimit(): void
    {
        $policy = RetentionPolicy::keeping(3);

        $this->assertSame([], $policy->expired([]));
        $this->assertSame([], $policy->expired(['a', 'b']));
        $this->assertSame([], $policy->expired(['a', 'b', 'c']));
    }

    public function testDiscardsTheOldestFirst(): void
    {
        $policy = RetentionPolicy::keeping(3);

        $this->assertSame(['a', 'b'], $policy->expired(['a', 'b', 'c', 'd', 'e']));
    }

    /**
     * The rule has no exemptions, so the answer depends only on how many there
     * are — never on what any of them happen to be.
     */
    public function testDiscardsExactlyTheExcess(): void
    {
        $policy = RetentionPolicy::keeping(2);

        $this->assertCount(1, $policy->expired(['a', 'b', 'c']));
        $this->assertCount(8, $policy->expired(range('a', 'j')));
    }

    /**
     * Zero would leave history looking enabled while retaining nothing.
     */
    public function testALimitBelowOneIsRaisedToOne(): void
    {
        $this->assertSame(1, RetentionPolicy::keeping(0)->limit);
        $this->assertSame(1, RetentionPolicy::keeping(-5)->limit);
        $this->assertSame(['a', 'b'], RetentionPolicy::keeping(0)->expired(['a', 'b', 'c']));
    }

    public function testDefaultKeepsTwenty(): void
    {
        $this->assertSame(RetentionPolicy::DEFAULT_LIMIT, RetentionPolicy::default()->limit);
        $this->assertSame(20, RetentionPolicy::DEFAULT_LIMIT);
    }
}
