<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Protobuf;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;
use Qcodr\Restate\Sdk\Protocol\ProtocolException;

final class ReaderSkipTest extends TestCase
{
    public function testSkipFixed64ConsumesEightBytes(): void
    {
        $reader = new Reader('01234567');

        $reader->skip(WireType::FIXED64);

        self::assertTrue($reader->atEnd());
    }

    public function testSkipFixed32ConsumesFourBytes(): void
    {
        $reader = new Reader('0123');

        $reader->skip(WireType::FIXED32);

        self::assertTrue($reader->atEnd());
    }

    public function testSkipUnknownWireTypeThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Cannot skip unknown wire type 3');

        (new Reader('payload'))->skip(3);
    }

    public function testSkipFixed64PastTheBufferEndThrows(): void
    {
        // Only five bytes available, but FIXED64 wants eight.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Attempted to read past end of buffer');

        (new Reader('short'))->skip(WireType::FIXED64);
    }

    public function testReadVarintOnATruncatedBufferThrows(): void
    {
        // A lone continuation byte (0x80) promises more bytes that never arrive.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected end of buffer while reading varint');

        (new Reader("\x80"))->readVarint();
    }

    public function testReadVarintRejectsAnOverlongEncoding(): void
    {
        // Eleven continuation bytes blow past the 64-bit shift budget.
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Varint is too long');

        (new Reader(\str_repeat("\x80", 11)))->readVarint();
    }
}
