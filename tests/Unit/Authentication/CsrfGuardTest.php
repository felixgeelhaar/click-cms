<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Authentication;

use Click\Cms\Application\Authentication\CsrfGuard;
use PHPUnit\Framework\TestCase;

final class CsrfGuardTest extends TestCase
{
    public function testSafeMethodsNeedNoToken(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'get', 'head'] as $method) {
            $this->assertTrue(CsrfGuard::isSafeMethod($method), $method);
        }
    }

    public function testStateChangingMethodsAreNotSafe(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'post'] as $method) {
            $this->assertFalse(CsrfGuard::isSafeMethod($method), $method);
        }
    }

    public function testGeneratedTokensAreLongAndUnique(): void
    {
        $a = CsrfGuard::generateToken();
        $b = CsrfGuard::generateToken();

        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $a);
    }

    public function testMatchesOnlyWhenIdentical(): void
    {
        $token = CsrfGuard::generateToken();

        $this->assertTrue(CsrfGuard::matches($token, $token));
        $this->assertFalse(CsrfGuard::matches($token, strrev($token)));
        $this->assertFalse(CsrfGuard::matches($token, substr($token, 0, -1)));
    }

    /**
     * A missing token must never satisfy the check, or an attacker simply omits
     * it — and an empty expected value must not make everything pass.
     */
    public function testEmptyOrMissingNeverMatches(): void
    {
        $token = CsrfGuard::generateToken();

        $this->assertFalse(CsrfGuard::matches($token, null));
        $this->assertFalse(CsrfGuard::matches($token, ''));
        $this->assertFalse(CsrfGuard::matches(null, $token));
        $this->assertFalse(CsrfGuard::matches('', ''));
        $this->assertFalse(CsrfGuard::matches(null, null));
    }

    public function testReadsTheTokenFromItsHeader(): void
    {
        $server = ['HTTP_X_CLICK_CSRF' => 'abc123'];

        $this->assertSame('abc123', CsrfGuard::tokenFromRequest($server));
    }

    public function testReturnsNullWhenTheHeaderIsAbsentOrEmpty(): void
    {
        $this->assertNull(CsrfGuard::tokenFromRequest([]));
        $this->assertNull(CsrfGuard::tokenFromRequest(['HTTP_X_CLICK_CSRF' => '']));
    }

    /**
     * Reading the token only from a header is deliberate: a plain cross-origin
     * form post cannot set one, which is the request shape being rejected.
     */
    public function testDoesNotAcceptTheTokenFromPostBody(): void
    {
        $_POST['csrf'] = 'abc123';

        $this->assertNull(CsrfGuard::tokenFromRequest([]));

        unset($_POST['csrf']);
    }
}
