<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\EndMessage;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\ProtocolException;

final class MessageCodecTrailingTest extends TestCase
{
    public function testDecodeAllRejectsTrailingBytesAfterTheLastFrame(): void
    {
        // One complete End frame followed by 3 stray bytes: too few to form another
        // header, so consume() stops while the offset is short of the buffer end.
        $buffer = MessageCodec::encode(new EndMessage()) . "\x01\x02\x03";

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Trailing bytes after last complete frame');

        MessageCodec::decodeAll($buffer);
    }
}
