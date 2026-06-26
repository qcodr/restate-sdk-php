<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\GetEagerStateKeysCommand;
use Qcodr\Restate\Sdk\Protocol\Message\StateKeys;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;

final class GetEagerStateKeysCommandTest extends TestCase
{
    public function testEncodesInlinedStateKeysAndName(): void
    {
        $command = new GetEagerStateKeysCommand(['a', 'b', 'c'], 'eager-keys');

        self::assertSame(MessageType::GetEagerStateKeysCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('eager-keys', $fields[12]);
        self::assertIsString($fields[14]);
        self::assertSame(['a', 'b', 'c'], StateKeys::decode($fields[14])->keys);
    }

    public function testEmptyKeyListStillEmitsAnInlinedStateKeysMessage(): void
    {
        // writeMessage(14, ...) is always present even when the StateKeys encoding is
        // empty, so an empty key list still emits a parseable (empty) field 14.
        $fields = self::fields((new GetEagerStateKeysCommand([]))->encode());

        self::assertArrayHasKey(14, $fields);
        self::assertIsString($fields[14]);
        self::assertSame('', $fields[14]);
        self::assertSame([], StateKeys::decode($fields[14])->keys);
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
