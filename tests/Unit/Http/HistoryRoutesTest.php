<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use Click\Cms\Http\CoreApiRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class HistoryRoutesTest extends TestCase
{
    /** @return array<string, callable> */
    private function routes(): array
    {
        return (new CoreApiRoutes(sys_get_temp_dir() . '/click-cms-no-such-base'))->routes();
    }

    public function testHistoryIsPartOfTheManagementApi(): void
    {
        $routes = $this->routes();

        $this->assertArrayHasKey('GET /api/pages/:slug/versions', $routes);
        $this->assertArrayHasKey('GET /api/pages/:slug/versions/:id', $routes);
        $this->assertArrayHasKey('POST /api/pages/:slug/versions/:id/restore', $routes);
    }

    /**
     * Route parameters are bound to handler arguments by name, so the names in
     * the path and in the signature have to agree.
     */
    public function testHandlerArgumentsMatchTheRouteParameters(): void
    {
        $expected = [
            'GET /api/pages/:slug/versions' => ['slug'],
            'GET /api/pages/:slug/versions/:id' => ['slug', 'id'],
            'POST /api/pages/:slug/versions/:id/restore' => ['slug', 'id'],
        ];

        foreach ($expected as $route => $parameters) {
            [$object, $method] = $this->routes()[$route];

            $actual = array_map(
                static fn (\ReflectionParameter $p): string => $p->getName(),
                (new ReflectionMethod($object, $method))->getParameters()
            );

            $this->assertSame($parameters, $actual, $route);
        }
    }

    /**
     * The delivery allowlist makes `GET pages/*` anonymous, which is the whole
     * point of a public read API. History hangs off the same prefix and must
     * not inherit that: it holds every unpublished draft the page has been in.
     *
     * Reached through reflection deliberately. The rule is a security boundary
     * and is private for good reason, but a regression here is silent — an
     * anonymous request would simply start succeeding.
     */
    public function testHistoryIsNotReachableWithoutASession(): void
    {
        $isPublic = new ReflectionMethod(Application::class, 'isPublicApiPath');
        $app = new Application(sys_get_temp_dir() . '/click-cms-no-such-base');

        // The public delivery paths this is carved out of.
        $this->assertTrue($isPublic->invoke($app, 'pages', 'GET'));
        $this->assertTrue($isPublic->invoke($app, 'pages/home', 'GET'));

        $this->assertFalse($isPublic->invoke($app, 'pages/home/versions', 'GET'));
        $this->assertFalse(
            $isPublic->invoke($app, 'pages/home/versions/20260721T104512.123456Z-a3f9', 'GET')
        );
        $this->assertFalse(
            $isPublic->invoke($app, 'pages/home/versions/20260721T104512.123456Z-a3f9/restore', 'POST')
        );
    }
}
