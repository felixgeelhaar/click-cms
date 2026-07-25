<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Plugin\ContentRefusedException;
use Click\Cms\Core\Application;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\StorageAuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * What a refusal looks like to whoever made the request.
 *
 * Two exceptions could reach the kernel and neither was caught, so a plugin
 * declining a save and a role that may not write both produced a 500 and a
 * stack trace. To an editor that reads as "the CMS is broken" rather than "your
 * content was not accepted" — and it is the difference between a product that
 * explains itself and one that appears to fall over.
 */
final class RefusalStatusTest extends TestCase
{
    private function dispatch(callable $handler): array
    {
        $app = new Application(dirname(__DIR__, 3));

        // A handler is [$object, $method]; an anonymous class gives us one whose
        // body we control, dispatched exactly as a route handler is.
        $subject = new class ($handler) {
            /** @var callable */
            private $handler;

            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function run(): array
            {
                return ($this->handler)();
            }
        };

        return (new \ReflectionMethod($app, 'executeHandler'))
            ->invoke($app, [$subject, 'run'], []);
    }

    public function testAPluginRefusingContentAnswers409WithItsReason(): void
    {
        $response = $this->dispatch(static function (): array {
            throw ContentRefusedException::refused(
                'content.before_save',
                ContentKey::page('home'),
                'This page has no headline.'
            );
        });

        // 409, not 403: the caller is entitled to make the request. What is not
        // ready is the state of the thing being written.
        $this->assertSame(409, $response['status']);
        $this->assertSame('This page has no headline.', $response['error']);
        // Naming the hook is what lets an editor find who to ask.
        $this->assertSame('content.before_save', $response['refusedBy']);
    }

    public function testAStorageAuthorisationFailureAnswers403(): void
    {
        $response = $this->dispatch(static function (): array {
            throw StorageAuthorizationException::denied('save', ContentKey::page('home'));
        });

        $this->assertSame(403, $response['status']);
        // Whatever wording the exception chose, the caller is told rather than
        // handed a stack trace.
        $this->assertNotSame('', $response['error']);
    }

    /** Neither catch may swallow a genuine fault — a bug must still be a bug. */
    public function testAnUnexpectedErrorIsNotTurnedIntoAPoliteRefusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a real bug');

        $this->dispatch(static function (): array {
            throw new \RuntimeException('a real bug');
        });
    }

    public function testAHandlerThatSucceedsIsUntouched(): void
    {
        $this->assertSame(
            ['data' => 'fine'],
            $this->dispatch(static fn (): array => ['data' => 'fine'])
        );
    }
}
