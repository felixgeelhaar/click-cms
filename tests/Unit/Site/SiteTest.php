<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Site;

use Click\Cms\Domain\Site\Site;
use Click\Cms\Domain\Site\SiteRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteTest extends TestCase
{
    /**
     * The id becomes a path segment, so a site called `../..` would put one
     * client's content somewhere quite different from where the configuration
     * says it goes.
     */
    public function testAnIdThatCouldEscapeItsDirectoryIsRefused(): void
    {
        foreach (['../evil', 'a/b', '', '.', '..', 'Uppercase', 'with space', '-leading'] as $id) {
            try {
                Site::fromArray(['id' => $id]);
                $this->fail("'{$id}' should have been refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnOrdinaryIdIsAccepted(): void
    {
        $this->assertSame('acme-eu', Site::fromArray(['id' => 'acme-eu'])->id);
    }

    /* ----------------------------------------------------------- hosts -- */

    public function testItMatchesADeclaredHost(): void
    {
        $site = Site::fromArray(['id' => 'acme', 'hosts' => ['acme.example.com']]);

        $this->assertTrue($site->matches('acme.example.com'));
        $this->assertFalse($site->matches('other.example.com'));
    }

    /**
     * The same site is the same site on :80, :443 and :8080 in development.
     */
    public function testAPortIsNotPartOfTheIdentity(): void
    {
        $site = Site::fromArray(['id' => 'acme', 'hosts' => ['acme.example.com']]);

        $this->assertTrue($site->matches('acme.example.com:8080'));
    }

    public function testHostMatchingIsCaseInsensitive(): void
    {
        $site = Site::fromArray(['id' => 'acme', 'hosts' => ['Acme.Example.COM']]);

        $this->assertTrue($site->matches('acme.example.com'));
    }

    public function testAWildcardMatchesSubdomains(): void
    {
        $site = Site::fromArray(['id' => 'acme', 'hosts' => ['*.acme.com']]);

        $this->assertTrue($site->matches('www.acme.com'));
        $this->assertTrue($site->matches('shop.acme.com'));
    }

    /**
     * `*.acme.com` and `acme.com` are different addresses. A site wanting both
     * says both, rather than discovering the rule by accident — and, more to the
     * point, rather than a wildcard silently claiming a bare domain another site
     * declared.
     */
    public function testAWildcardDoesNotClaimTheBareDomain(): void
    {
        $site = Site::fromArray(['id' => 'acme', 'hosts' => ['*.acme.com']]);

        $this->assertFalse($site->matches('acme.com'));
    }

    /* ------------------------------------------------------------ roots -- */

    /**
     * The property that makes multi-site additive: an installation that never
     * declares a site keeps `content/` and `data/` exactly where they were.
     */
    public function testThePrimarySiteKeepsTheOriginalLayout(): void
    {
        $this->assertSame('', Site::primary()->rootSuffix());
    }

    public function testEveryOtherSiteLivesUnderItsOwnDirectory(): void
    {
        $this->assertSame('/sites/acme', Site::fromArray(['id' => 'acme'])->rootSuffix());
    }
}
