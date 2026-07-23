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
     * With nothing exempt the answer depends only on how many there are, never
     * on what any of them happen to be.
     */
    public function testDiscardsExactlyTheExcess(): void
    {
        $policy = RetentionPolicy::keeping(2);

        $this->assertCount(1, $policy->expired(['a', 'b', 'c']));
        $this->assertCount(8, $policy->expired(range('a', 'j')));
    }

    /* -------------------------------------------------------- exemptions -- */

    /**
     * The reason the exemption exists at all.
     *
     * The newest version is the working copy and a published version is what
     * the live site is serving. Discarding either is not tidying, it is losing
     * the current document — and under the old no-exemptions rule the
     * twenty-first edit did exactly that to a page published twenty-one edits
     * ago.
     */
    public function testAnExemptVersionIsNeverDiscarded(): void
    {
        $policy = RetentionPolicy::keeping(3);

        $discarded = $policy->expired(['a', 'b', 'c', 'd', 'e'], ['a']);

        $this->assertNotContains('a', $discarded);
        $this->assertSame(['b', 'c'], $discarded);
    }

    /**
     * Sparing more than the excess would allow means the limit gives way, not
     * the exemption. Twenty-one copies of a few kilobytes is not a problem
     * anybody has; losing the published one is.
     */
    public function testTheLimitYieldsRatherThanTheExemption(): void
    {
        $policy = RetentionPolicy::keeping(1);

        // Three entries, room for one, but the two oldest must both survive —
        // so three go in and two stay, one more than the limit allows.
        $this->assertSame(['c'], $policy->expired(['a', 'b', 'c'], ['a', 'b']));
    }

    /**
     * A caller that cannot tell whether a document is published passes what it
     * has, so an identifier that is not in the chain must simply not matter.
     */
    public function testAnExemptionThatIsNotInTheChainIsIgnored(): void
    {
        $policy = RetentionPolicy::keeping(2);

        $this->assertSame(['a'], $policy->expired(['a', 'b', 'c'], ['zzz']));
    }

    public function testExemptionsDoNothingWhileUnderTheLimit(): void
    {
        $this->assertSame([], RetentionPolicy::keeping(3)->expired(['a', 'b'], ['a']));
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
