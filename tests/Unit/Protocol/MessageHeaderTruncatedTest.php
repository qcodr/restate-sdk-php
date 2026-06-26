<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\MessageHeader;
use Qcodr\Restate\Sdk\Protocol\ProtocolException;

final class MessageHeaderTruncatedTest extends TestCase
{
    public function testDecodeRejectsAHeaderShorterThanEightBytes(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Truncated message header');

        MessageHeader::decode("\x00\x00\x00");
    }

    public function testDecodeRejectsAnEmptyBuffer(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Truncated message header');

        MessageHeader::decode('');
    }
}
