<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Migration;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Migration\WordPressWxrLocalePreflight;
use PHPUnit\Framework\TestCase;

final class WordPressWxrLocalePreflightTest extends TestCase
{
    public function testRejectsUnconfiguredOrInvalidLocale(): void
    {
        $config = CoreConfig::fromArray(['core' => ['languages' => ['available' => ['de']]]]);

        $this->expectException(\InvalidArgumentException::class);
        WordPressWxrLocalePreflight::validate($config, 'fr');
    }

    public function testReturnsNormalizedConfiguredLocale(): void
    {
        $config = CoreConfig::fromArray(['core' => ['languages' => ['available' => ['de', 'pt-BR']]]]);

        self::assertSame('pt-BR', WordPressWxrLocalePreflight::validate($config, 'pt-br'));
    }
}
