<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\CompleteAwakeableCommand;
use Restate\Sdk\Protocol\Message\Failure;
use Restate\Sdk\Protocol\Message\Value;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;

final class CompleteAwakeableCommandTest extends TestCase
{
    public function testResolveCarriesAwakeableIdValueAndName(): void
    {
        $command = CompleteAwakeableCommand::resolve('awk-id', 'data', 'name-x');

        self::assertSame(MessageType::CompleteAwakeableCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('awk-id', $fields[1]);
        self::assertSame('name-x', $fields[12]);
        self::assertArrayNotHasKey(3, $fields, 'a resolved awakeable must not carry a failure');
        self::assertArrayNotHasKey(11, $fields, 'awakeable completion is addressed by id, not a completion index');
        self::assertSame('data', Value::decode($fields[2])->content);
    }

    public function testRejectCarriesFailure(): void
    {
        $command = CompleteAwakeableCommand::reject('awk', new Failure(2, 'no'));

        $fields = self::fields($command->encode());
        self::assertSame('awk', $fields[1]);
        self::assertArrayNotHasKey(2, $fields, 'a rejected awakeable must not carry a value');

        $failure = Failure::decode($fields[3]);
        self::assertSame(2, $failure->code);
        self::assertSame('no', $failure->message);
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
