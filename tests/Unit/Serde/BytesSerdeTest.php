<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Serde;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Serde\BytesSerde;
use Qcodr\Restate\Sdk\Serde\SerializationException;
use Stringable;

final class BytesSerdeTest extends TestCase
{
    public function testRoundTripsRawBytesUnchanged(): void
    {
        $serde = new BytesSerde();
        $bytes = "\x00\x01\x02\xffbinary\x00payload";

        $serialized = $serde->serialize($bytes);

        self::assertSame($bytes, $serialized);
        self::assertSame($bytes, $serde->deserialize($serialized));
    }

    public function testDeserializeReturnsBytesVerbatimIgnoringTypeHint(): void
    {
        $serde = new BytesSerde();

        self::assertSame('raw', $serde->deserialize('raw', 'int'));
        self::assertSame('', $serde->deserialize(''));
    }

    public function testSerializesStringable(): void
    {
        $serde = new BytesSerde();
        $value = new class () implements Stringable {
            public function __toString(): string
            {
                return 'from-stringable';
            }
        };

        self::assertSame('from-stringable', $serde->serialize($value));
    }

    public function testThrowsOnNonStringValue(): void
    {
        $serde = new BytesSerde();

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('BytesSerde can only serialize a string or Stringable, got int');

        $serde->serialize(42);
    }
}
