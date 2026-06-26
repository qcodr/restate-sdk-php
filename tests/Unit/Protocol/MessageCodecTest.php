<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Frame;
use Qcodr\Restate\Sdk\Protocol\Message\EndMessage;
use Qcodr\Restate\Sdk\Protocol\Message\OutputCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageHeader;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;

final class MessageCodecTest extends TestCase
{
    public function testEncodesEndMessageAsBareHeader(): void
    {
        $encoded = MessageCodec::encode(new EndMessage());

        self::assertSame(MessageHeader::SIZE, \strlen($encoded));
        $frames = MessageCodec::decodeAll($encoded);
        self::assertCount(1, $frames);
        self::assertSame(MessageType::End, $frames[0]->type());
        self::assertSame('', $frames[0]->payload);
    }

    public function testOutputCommandRoundTripsThroughAFrame(): void
    {
        $encoded = MessageCodec::encode(OutputCommand::success('"hi"'));

        $frames = MessageCodec::decodeAll($encoded);
        self::assertCount(1, $frames);
        self::assertSame(MessageType::OutputCommand, $frames[0]->type());

        // payload: field 14 (Value) -> content '"hi"'
        $reader = new Reader($frames[0]->payload);
        [$field] = $reader->readTag();
        self::assertSame(14, $field);
        self::assertSame('"hi"', Value::decode($reader->readLengthDelimited())->content);
    }

    public function testDecodesMultipleConcatenatedFrames(): void
    {
        $buffer = MessageCodec::encode(OutputCommand::success('a')) . MessageCodec::encode(new EndMessage());

        $frames = MessageCodec::decodeAll($buffer);

        self::assertCount(2, $frames);
        self::assertSame(MessageType::OutputCommand, $frames[0]->type());
        self::assertSame(MessageType::End, $frames[1]->type());
    }

    public function testConsumeReturnsNullOnPartialFrame(): void
    {
        $full = MessageCodec::encode(OutputCommand::success('partial'));
        $partial = \substr($full, 0, \strlen($full) - 2);

        $offset = 0;
        self::assertNull(MessageCodec::consume($partial, $offset));
        self::assertSame(0, $offset);
    }

    public function testConsumeAdvancesOffsetOnCompleteFrame(): void
    {
        $buffer = MessageCodec::encode(new EndMessage());

        $offset = 0;
        $frame = MessageCodec::consume($buffer, $offset);
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame(\strlen($buffer), $offset);
    }
}
