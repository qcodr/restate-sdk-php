<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Serde;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Serde\JsonSerde;
use Restate\Sdk\Serde\SerializationException;

final class JsonSerdeTest extends TestCase
{
    public function testSerializesScalarsAndStructures(): void
    {
        $serde = new JsonSerde();

        self::assertSame('42', $serde->serialize(42));
        self::assertSame('"hello"', $serde->serialize('hello'));
        self::assertSame('{"a":1,"b":[2,3]}', $serde->serialize(['a' => 1, 'b' => [2, 3]]));
    }

    public function testLeavesSlashesAndUnicodeUnescaped(): void
    {
        $serde = new JsonSerde();

        self::assertSame('"a/b"', $serde->serialize('a/b'));
        self::assertSame('"héllo"', $serde->serialize('héllo'));
    }

    public function testSerializeWrapsEncodeFailureAsSerializationException(): void
    {
        $serde = new JsonSerde();
        $prefix = 'Failed to JSON-encode value: ';

        try {
            $serde->serialize(\NAN);
            self::fail('expected a SerializationException');
        } catch (SerializationException $e) {
            // The prefix must lead and the underlying encoder message must follow.
            self::assertStringStartsWith($prefix, $e->getMessage());
            self::assertGreaterThan(\strlen($prefix), \strlen($e->getMessage()));
        }
    }

    public function testEmptyBytesDeserializeToNull(): void
    {
        self::assertNull((new JsonSerde())->deserialize(''));
    }

    public function testDeserializeWrapsDecodeFailureAsSerializationException(): void
    {
        $serde = new JsonSerde();
        $prefix = 'Failed to JSON-decode value: ';

        try {
            $serde->deserialize('{not valid json');
            self::fail('expected a SerializationException');
        } catch (SerializationException $e) {
            self::assertStringStartsWith($prefix, $e->getMessage());
            self::assertGreaterThan(\strlen($prefix), \strlen($e->getMessage()));
        }
    }

    public function testScalarTypeHintsCoerceDecodedValue(): void
    {
        $serde = new JsonSerde();

        self::assertSame(7, $serde->deserialize('7', 'int'));
        self::assertSame(7, $serde->deserialize('7.9', 'int'));
        self::assertSame(3.5, $serde->deserialize('3.5', 'float'));
        self::assertSame(2.0, $serde->deserialize('2', 'float'));
        self::assertSame('9', $serde->deserialize('9', 'string'));
        self::assertTrue($serde->deserialize('1', 'bool'));
        self::assertFalse($serde->deserialize('0', 'bool'));
    }

    public function testUnknownOrMissingTypeReturnsDecodedValueVerbatim(): void
    {
        $serde = new JsonSerde();

        self::assertSame(5, $serde->deserialize('5'));
        self::assertSame('x', $serde->deserialize('"x"', 'DateTimeImmutable'));
    }

    public function testNonScalarDecodedValuesBypassCoercion(): void
    {
        $serde = new JsonSerde();

        // Arrays/objects are not scalars, so the type hint must not touch them.
        self::assertSame(['a' => 1], $serde->deserialize('{"a":1}', 'int'));
        self::assertSame([1, 2, 3], $serde->deserialize('[1,2,3]', 'string'));
        self::assertNull($serde->deserialize('null', 'int'));
    }

    public function testRoundTripsTypedScalarsThroughBothSides(): void
    {
        $serde = new JsonSerde();

        self::assertSame(123, $serde->deserialize($serde->serialize(123), 'int'));
        self::assertTrue($serde->deserialize($serde->serialize(true), 'bool'));
    }
}
