<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\ServerEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Reading a value the web server was told to set.
 *
 * Found in production, on the hosting this project is written for. Apache's
 * `SetEnv CLICK_CMS_ROOT …` in an .htaccess reached PHP as
 * `REDIRECT_CLICK_CMS_ROOT` and under no other name: Apache prefixes variables
 * with `REDIRECT_` once a request has been through an internal redirect, and on
 * a `cgi-fcgi` SAPI every PHP request has been. Reading only `getenv()` — which
 * is what the first release did — sees nothing, and the installation behaves as
 * though it was never configured at all.
 *
 * So all three names are consulted, in the order of how directly they were set.
 */
final class ServerEnvironmentTest extends TestCase
{
    public function testAProcessEnvironmentVariableIsFound(): void
    {
        $this->assertSame('/app', ServerEnvironment::lookup('CLICK_CMS_ROOT', [], ['CLICK_CMS_ROOT' => '/app']));
    }

    public function testAServerVariableIsFound(): void
    {
        $this->assertSame('/app', ServerEnvironment::lookup('CLICK_CMS_ROOT', ['CLICK_CMS_ROOT' => '/app']));
    }

    /** The one that was missed, and the only one this host provides. */
    public function testARedirectPrefixedVariableIsFound(): void
    {
        $this->assertSame(
            '/app',
            ServerEnvironment::lookup('CLICK_CMS_ROOT', ['REDIRECT_CLICK_CMS_ROOT' => '/app'])
        );
    }

    /**
     * Apache adds a further prefix per internal redirect, so a request that has
     * been through two arrives as REDIRECT_REDIRECT_. Rare, but the failure it
     * causes is indistinguishable from the setting being absent.
     */
    public function testARepeatedlyRedirectedVariableIsFound(): void
    {
        $this->assertSame(
            '/app',
            ServerEnvironment::lookup('CLICK_CMS_ROOT', ['REDIRECT_REDIRECT_CLICK_CMS_ROOT' => '/app'])
        );
    }

    /** Set more directly wins: the unprefixed name is the operator's own. */
    public function testTheLeastRedirectedValueWins(): void
    {
        $server = [
            'CLICK_CMS_ROOT' => '/direct',
            'REDIRECT_CLICK_CMS_ROOT' => '/redirected',
        ];

        $this->assertSame('/direct', ServerEnvironment::lookup('CLICK_CMS_ROOT', $server));
    }

    public function testTheProcessEnvironmentWinsOverTheRequest(): void
    {
        $this->assertSame(
            '/from-env',
            ServerEnvironment::lookup('CLICK_CMS_ROOT', ['CLICK_CMS_ROOT' => '/from-server'], ['CLICK_CMS_ROOT' => '/from-env'])
        );
    }

    public function testAnAbsentVariableIsNull(): void
    {
        $this->assertNull(ServerEnvironment::lookup('CLICK_CMS_ROOT', [], []));
    }

    /** An empty value is an absent one: nothing downstream can use it. */
    public function testAnEmptyValueIsTreatedAsAbsent(): void
    {
        $this->assertNull(ServerEnvironment::lookup('CLICK_CMS_ROOT', ['CLICK_CMS_ROOT' => '   '], []));
    }

    public function testANonStringValueIsIgnored(): void
    {
        $this->assertNull(ServerEnvironment::lookup('CLICK_CMS_ROOT', ['CLICK_CMS_ROOT' => ['/app']], []));
    }
}
