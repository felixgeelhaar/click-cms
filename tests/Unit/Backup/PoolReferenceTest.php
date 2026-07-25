<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Domain\Backup\PoolReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A pool reference arrives from a manifest, which is a file that may have been
 * written by somebody else, and it becomes a path. So what it is allowed to
 * contain is a security boundary, not a naming convention.
 */
final class PoolReferenceTest extends TestCase
{
    private const DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testAReferenceIsTheDigestAndTheExtension(): void
    {
        $this->assertSame(
            'pool/' . self::DIGEST . '.png',
            PoolReference::for(self::DIGEST, 'png')
        );
    }

    public function testALeadingDotOnTheExtensionIsAccepted(): void
    {
        $this->assertSame(
            'pool/' . self::DIGEST . '.jpg',
            PoolReference::for(self::DIGEST, '.JPG')
        );
    }

    /**
     * The extension comes from an uploaded file's name and is decoration only —
     * the digest is the identity. Anything that is not a plain word is dropped
     * rather than sanitised into something that still resembles it.
     */
    public function testAnExtensionThatIsNotAWordIsDroppedEntirely(): void
    {
        $this->assertSame('pool/' . self::DIGEST, PoolReference::for(self::DIGEST, '../../evil'));
        $this->assertSame('pool/' . self::DIGEST, PoolReference::for(self::DIGEST, 'php5/x'));
        $this->assertSame('pool/' . self::DIGEST, PoolReference::for(self::DIGEST, ''));
    }

    public function testAWellFormedReferenceIsValid(): void
    {
        $this->assertTrue(PoolReference::isValid('pool/' . self::DIGEST . '.png'));
        $this->assertTrue(PoolReference::isValid('pool/' . self::DIGEST));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidReferences(): array
    {
        return [
            ['pool/../../../etc/passwd'],
            ['pool/' . self::DIGEST . '/../../x'],
            ['/etc/passwd'],
            ['pool/not-a-digest.png'],
            // Upper-case hex is not what hash() emits, so it is not one of ours.
            ['pool/' . strtoupper(self::DIGEST) . '.png'],
            ['pool/' . self::DIGEST . '.thisextensioniswaytoolong'],
            ['elsewhere/' . self::DIGEST . '.png'],
            [''],
            ['pool/'],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function testAMalformedReferenceIsRefused(string $reference): void
    {
        $this->assertFalse(PoolReference::isValid($reference));
        $this->assertNull(PoolReference::digestOf($reference));
    }

    public function testTheDigestCanBeReadBackOutOfAReference(): void
    {
        $this->assertSame(self::DIGEST, PoolReference::digestOf('pool/' . self::DIGEST . '.png'));
        $this->assertSame(self::DIGEST, PoolReference::digestOf('pool/' . self::DIGEST));
    }

    /**
     * The property the whole pool rests on: identical bytes produce identical
     * names, which is why a nightly backup of an unchanged library writes
     * nothing at all after the first night.
     */
    public function testIdenticalBytesProduceIdenticalReferences(): void
    {
        $one = PoolReference::for(hash('sha256', 'the same picture'), 'jpg');
        $two = PoolReference::for(hash('sha256', 'the same picture'), 'jpg');

        $this->assertSame($one, $two);
        $this->assertNotSame($one, PoolReference::for(hash('sha256', 'a different one'), 'jpg'));
    }
}
