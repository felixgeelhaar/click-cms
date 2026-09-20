<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\ApiFault;
use PHPUnit\Framework\TestCase;

/**
 * The additive fault envelope: `error` stays the human string clients already
 * read; `code` is an optional stable machine token. Existing callers that only
 * look at `error` keep working.
 */
final class ApiFaultTest extends TestCase
{
    public function testCarriesStatusErrorAndCode(): void
    {
        $fault = ApiFault::of(403, 'You do not have permission to change the theme.', 'forbidden');

        $this->assertSame(403, $fault['status']);
        $this->assertSame('You do not have permission to change the theme.', $fault['error']);
        $this->assertSame('forbidden', $fault['code']);
    }

    public function testKeepsErrorAlongsideCodeSoExistingClientsStillWork(): void
    {
        $fault = ApiFault::of(401, 'Not authenticated', 'unauthenticated');

        $this->assertArrayHasKey('error', $fault);
        $this->assertArrayHasKey('code', $fault);
        $this->assertIsString($fault['error']);
        $this->assertIsString($fault['code']);
    }
}
