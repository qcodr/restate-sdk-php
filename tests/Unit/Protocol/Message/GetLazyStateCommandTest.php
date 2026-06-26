<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\GetLazyStateCommand;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;

final class GetLazyStateCommandTest extends TestCase
{
    public function testEncodesKeyCompletionIdAndName(): void
    {
        $command = new GetLazyStateCommand('user:42', 5, 'lazy-get');

        self::assertSame(MessageType::GetLazyStateCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('user:42', $fields[1]);
        self::assertSame(5, $fields[11]);
        self::assertSame('lazy-get', $fields[12]);
    }

    public function testKeyIsAlwaysPresentEvenWhenEmpty(): void
    {
        // The key is presence-sensitive (writeBytesPresent): an empty key must
        // still emit field 1 so the runtime can tell "" apart from absent.
        $fields = self::fields((new GetLazyStateCommand('', 1))->encode());

        self::assertArrayHasKey(1, $fields);
        self::assertSame('', $fields[1]);
    }

    /** @return array<int, int|string> */
    private static function fields(string $bytes): array
    {
        $reader = new Reader($bytes);
        $fields = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            $fields[$field] = match ($wire) {
                WireType::VARINT => $reader->readVarint(),
                WireType::LENGTH_DELIMITED => $reader->readLengthDelimited(),
                default => $reader->skip($wire),
            };
        }

        return $fields;
    }
}
