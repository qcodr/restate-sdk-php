<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Protobuf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;
use Restate\Sdk\Protocol\Protobuf\Writer;

final class CodecTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function varintCases(): iterable
    {
        yield 'zero' => [0, "\x00"];
        yield 'one' => [1, "\x01"];
        yield 'max single byte' => [127, "\x7f"];
        yield 'two bytes' => [128, "\x80\x01"];
        yield '300' => [300, "\xac\x02"];
        yield 'three bytes' => [16384, "\x80\x80\x01"];
    }

    #[DataProvider('varintCases')]
    public function testVarintEncodesToExpectedBytes(int $value, string $expected): void
    {
        self::assertSame($expected, Writer::varint($value));
    }

    #[DataProvider('varintCases')]
    public function testVarintRoundTrips(int $value): void
    {
        $reader = new Reader(Writer::varint($value));
        self::assertSame($value, $reader->readVarint());
        self::assertTrue($reader->atEnd());
    }

    public function testLargeVarintRoundTrips(): void
    {
        $value = (1 << 40) + 12345;
        $reader = new Reader(Writer::varint($value));
        self::assertSame($value, $reader->readVarint());
    }

    public function testNegativeLengthDelimitedIsRejected(): void
    {
        // A 10-byte varint with bit 63 set decodes to a negative PHP int; used as a
        // length it must be rejected, not silently corrupt the offset (DoS guard).
        $reader = new Reader(Writer::varint(\PHP_INT_MIN));

        $this->expectException(\Restate\Sdk\Protocol\ProtocolException::class);
        $reader->readLengthDelimited();
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function highBitVarintCases(): iterable
    {
        // uint64 values with bit 63 set are negative PHP ints; they must encode as
        // the full ten-byte varint preserving the bit pattern.
        yield '2^63 (PHP_INT_MIN)' => [\PHP_INT_MIN, \str_repeat("\x80", 9) . "\x01"];
        yield 'uint64 max (-1)' => [-1, \str_repeat("\xff", 9) . "\x01"];
    }

    #[DataProvider('highBitVarintCases')]
    public function testHighBitVarintEncodesAndRoundTrips(int $value, string $expected): void
    {
        $encoded = Writer::varint($value);
        self::assertSame($expected, $encoded);
        self::assertSame($value, (new Reader($encoded))->readVarint());
    }

    public function testScalarDefaultsAreOmitted(): void
    {
        $encoded = (new Writer())
            ->writeUint32(3, 0)
            ->writeString(2, '')
            ->writeBool(5, false)
            ->toString();

        self::assertSame('', $encoded);
    }

    public function testPresentHelpersEmitEmptyValues(): void
    {
        $encoded = (new Writer())->writeStringPresent(2, '')->toString();

        $reader = new Reader($encoded);
        [$field, $wire] = $reader->readTag();
        self::assertSame(2, $field);
        self::assertSame(WireType::LENGTH_DELIMITED, $wire);
        self::assertSame('', $reader->readLengthDelimited());
    }

    public function testFieldRoundTrip(): void
    {
        $encoded = (new Writer())
            ->writeUint32(1, 42)
            ->writeStringPresent(2, 'hello')
            ->writeBool(3, true)
            ->toString();

        $reader = new Reader($encoded);

        [$field, $wire] = $reader->readTag();
        self::assertSame([1, WireType::VARINT], [$field, $wire]);
        self::assertSame(42, $reader->readVarint());

        [$field, $wire] = $reader->readTag();
        self::assertSame([2, WireType::LENGTH_DELIMITED], [$field, $wire]);
        self::assertSame('hello', $reader->readLengthDelimited());

        [$field] = $reader->readTag();
        self::assertSame(3, $field);
        self::assertSame(1, $reader->readVarint());

        self::assertTrue($reader->atEnd());
    }
}
