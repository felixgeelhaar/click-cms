<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Domain;

use Click\Cms\Domain\ValueObjects\PluginId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PluginIdTest extends TestCase
{
    public function testCanCreateFromString(): void
    {
        $id = PluginId::fromString('my-plugin');
        $this->assertEquals('my-plugin', $id->value);
    }

    public function testCanGenerateFromName(): void
    {
        $id = PluginId::generate('My Awesome Plugin');
        $this->assertEquals('my-awesome-plugin', $id->value);
    }

    public function testThrowsExceptionForEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginId::fromString('');
    }

    public function testThrowsExceptionForInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginId::fromString('my_plugin');
    }

    public function testCanConvertToString(): void
    {
        $id = PluginId::fromString('test-plugin');
        $this->assertEquals('test-plugin', (string) $id);
    }
}
