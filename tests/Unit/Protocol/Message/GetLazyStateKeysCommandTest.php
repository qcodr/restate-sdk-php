<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\GetLazyStateKeysCommand;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;

final class GetLazyStateKeysCommandTest extends TestCase
{
    public function testEncodesCompletionIdAndName(): void
    {
        $command = new GetLazyStateKeysCommand(5, 'lazy-keys');

        self::assertSame(MessageType::GetLazyStateKeysCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame(5, $fields[11]);
        self::assertSame('lazy-keys', $fields[12]);
    }

    public function testZeroCompletionIdAndDefaultNameProduceEmptyPayload(): void
    {
        // resultCompletionId uses writeUint32 (proto3 default omitted) and name uses
        // writeString, so a zero id with the default name encodes to nothing.
        self::assertSame('', (new GetLazyStateKeysCommand(0))->encode());
    }

    /** @return array<int, int|string> */
    private static function fields(string $bytes): array
    {
        $reader = new Reader($bytes);
        $fields = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($wire === WireType::VARINT) {
                $fields[$field] = $reader->readVarint();
            } elseif ($wire === WireType::LENGTH_DELIMITED) {
                $fields[$field] = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return $fields;
    }
}
