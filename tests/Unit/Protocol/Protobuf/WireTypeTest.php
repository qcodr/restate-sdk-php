<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Protobuf;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;
use Restate\Sdk\Protocol\Protobuf\Writer;

final class WireTypeTest extends TestCase
{
    public function testEachWireTypeSurvivesTagEncodingRoundTrip(): void
    {
        // A tag packs (field << 3) | wireType into a varint; readTag recovers both.
        // Round-tripping every wire type through field 3 proves the constants are
        // the low-3-bit codes the encoder and decoder agree on.
        foreach ([
            WireType::VARINT,
            WireType::FIXED64,
            WireType::LENGTH_DELIMITED,
            WireType::FIXED32,
        ] as $wireType) {
            $reader = new Reader(Writer::varint(Writer::tag(3, $wireType)));

            [$field, $decodedWire] = $reader->readTag();

            self::assertSame(3, $field);
            self::assertSame($wireType, $decodedWire);
        }
    }

    public function testWireTypesAreDistinctLowThreeBitCodes(): void
    {
        $codes = [
            WireType::VARINT,
            WireType::FIXED64,
            WireType::LENGTH_DELIMITED,
            WireType::FIXED32,
        ];

        self::assertCount(4, \array_unique($codes));
        foreach ($codes as $code) {
            self::assertSame($code, $code & 0x07, 'a wire type must fit in the low three tag bits');
        }
    }

    public function testItIsANonInstantiableConstantsHolder(): void
    {
        $class = new ReflectionClass(WireType::class);
        $constructor = $class->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate(), 'WireType must not be instantiable through normal construction');

        // Exercise the intentionally empty constructor to confirm it is a pure no-op.
        $constructor->setAccessible(true);
        $instance = $class->newInstanceWithoutConstructor();
        $constructor->invoke($instance);

        self::assertInstanceOf(WireType::class, $instance);
    }
}
