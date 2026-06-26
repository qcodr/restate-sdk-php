<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\ClearStateCommand;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;

final class ClearStateCommandTest extends TestCase
{
    public function testEncodesKeyInFieldOneAndNameInFieldTwelve(): void
    {
        $command = new ClearStateCommand('user:7', 'clear-x');

        self::assertSame(MessageType::ClearStateCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('user:7', $fields[1]);
        self::assertSame('clear-x', $fields[12]);
    }

    public function testKeyIsPresentEvenWhenEmptyAndDefaultNameIsOmitted(): void
    {
        // key uses writeBytesPresent: an empty key must still emit field 1 so the
        // runtime distinguishes "" from absent. name uses writeString, so the
        // default empty name is omitted entirely.
        $fields = self::fields((new ClearStateCommand(''))->encode());

        self::assertArrayHasKey(1, $fields);
        self::assertSame('', $fields[1]);
        self::assertArrayNotHasKey(12, $fields);
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
