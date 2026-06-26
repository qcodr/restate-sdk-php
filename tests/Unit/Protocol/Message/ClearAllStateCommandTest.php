<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\ClearAllStateCommand;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;

final class ClearAllStateCommandTest extends TestCase
{
    public function testEncodesNameInFieldTwelve(): void
    {
        $command = new ClearAllStateCommand('clear-all');

        self::assertSame(MessageType::ClearAllStateCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('clear-all', $fields[12]);
    }

    public function testDefaultNameProducesAnEmptyPayload(): void
    {
        // The only field is the optional name (writeString), so the default empty
        // name yields a completely empty payload.
        self::assertSame('', (new ClearAllStateCommand())->encode());
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
