<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\MessageHeader;
use Qcodr\Restate\Sdk\Protocol\MessageType;

final class MessageHeaderTest extends TestCase
{
    public function testStartHeaderEncodesToEightBigEndianBytes(): void
    {
        $header = new MessageHeader(MessageType::Start->value, 5);

        self::assertSame("\x00\x00\x00\x00\x00\x00\x00\x05", $header->encode());
    }

    public function testRoundTripPreservesTypeAndLength(): void
    {
        $header = new MessageHeader(MessageType::OutputCommand->value, 1234);

        $decoded = MessageHeader::decode($header->encode());

        self::assertSame(MessageType::OutputCommand->value, $decoded->typeCode);
        self::assertSame(1234, $decoded->length);
        self::assertFalse($decoded->requestedAck);
    }

    public function testHighTypeCodeRoundTrips(): void
    {
        // SignalNotification = 0xFBFF sets bit 63 once shifted, exercising the
        // signed-int path of pack/unpack 'J'.
        $header = new MessageHeader(MessageType::SignalNotification->value, 7);

        $decoded = MessageHeader::decode($header->encode());

        self::assertSame(0xFBFF, $decoded->typeCode);
        self::assertSame(7, $decoded->length);
    }

    public function testRequestedAckFlagIsBit47(): void
    {
        $header = new MessageHeader(MessageType::ProposeRunCompletion->value, 3, requestedAck: true);

        $encoded = $header->encode();
        $decoded = MessageHeader::decode($encoded);

        self::assertTrue($decoded->requestedAck);
        self::assertSame(MessageType::ProposeRunCompletion->value, $decoded->typeCode);
        self::assertSame(3, $decoded->length);
        // bit 47 lives in byte index 2 (big-endian): 0x8000_0000_0000.
        self::assertSame(0x80, \ord($encoded[2]));
    }

    public function testAckFlagDefaultsOff(): void
    {
        $decoded = MessageHeader::decode((new MessageHeader(MessageType::End->value, 0))->encode());

        self::assertFalse($decoded->requestedAck);
    }
}
