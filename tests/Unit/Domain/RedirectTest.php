<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\Redirect\Redirect;
use Click\Cms\Domain\Redirect\RedirectRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Redirect rules, and the safety around their destinations.
 *
 * A destination is stored data that becomes a Location header — so, like a menu
 * target, it must never be able to carry a `javascript:` scheme into the site's
 * own origin.
 */
final class RedirectTest extends TestCase
{
    public function testAnOnSitePathAndAnHttpUrlAreValidDestinations(): void
    {
        $this->assertSame('/new', Redirect::fromArray(['from' => '/old', 'to' => '/new'])->to);
        $this->assertSame(
            'https://example.com',
            Redirect::fromArray(['from' => '/old', 'to' => 'https://example.com'])->to
        );
    }

    public function testAJavascriptDestinationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Redirect::fromArray(['from' => '/old', 'to' => 'javascript:alert(1)']);
    }

    public function testADataUrlDestinationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Redirect::fromArray(['from' => '/old', 'to' => 'data:text/html,<script>alert(1)</script>']);
    }

    public function testAMissingFromIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Redirect::fromArray(['to' => '/new']);
    }

    public function testARuleCannotPointAPathAtItself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Redirect::fromArray(['from' => '/loop', 'to' => '/loop/']);
    }

    public function testMatchingIgnoresSurroundingSlashes(): void
    {
        $rule = Redirect::fromArray(['from' => '/old-page', 'to' => '/new-page']);

        $this->assertTrue($rule->matches('old-page'));
        $this->assertTrue($rule->matches('/old-page/'));
        $this->assertFalse($rule->matches('/other'));
    }

    public function testPermanentIsThreeOhOneTemporaryIsThreeOhTwo(): void
    {
        $this->assertSame(301, Redirect::fromArray(['from' => '/a', 'to' => '/b', 'permanent' => true])->statusCode());
        $this->assertSame(302, Redirect::fromArray(['from' => '/a', 'to' => '/b', 'permanent' => false])->statusCode());
    }

    /* ------------------------------------------------------- the set -- */

    public function testTheRuleSetReturnsTheFirstMatch(): void
    {
        $rules = RedirectRules::fromArray([
            ['from' => '/one', 'to' => '/first'],
            ['from' => '/two', 'to' => '/second'],
        ]);

        $this->assertSame('/second', $rules->match('/two')?->to);
        $this->assertNull($rules->match('/nothing'));
    }

    public function testAMalformedRuleIsSkippedNotFatal(): void
    {
        // A stored rule with a hostile destination must not take down every 404.
        $rules = RedirectRules::fromArray([
            ['from' => '/bad', 'to' => 'javascript:alert(1)'],
            ['from' => '/good', 'to' => '/fine'],
        ]);

        $this->assertSame(1, $rules->count());
        $this->assertNull($rules->match('/bad'));
        $this->assertSame('/fine', $rules->match('/good')?->to);
    }
}
