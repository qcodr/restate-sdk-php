<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\CompletePromiseCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\GetPromiseCommand;
use Qcodr\Restate\Sdk\Protocol\Message\PeekPromiseCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;

final class PromiseCommandTest extends TestCase
{
    public function testGetPromiseEncodesKeyCompletionIdAndName(): void
    {
        $command = new GetPromiseCommand('promise-key', 7, 'await-result');

        self::assertSame(MessageType::GetPromiseCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('promise-key', $fields[1]);
        self::assertSame(7, $fields[11]);
        self::assertSame('await-result', $fields[12]);
    }

    public function testPeekPromiseEncodesLikeGetPromise(): void
    {
        $command = new PeekPromiseCommand('p', 3, 'peek');

        self::assertSame(MessageType::PeekPromiseCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('p', $fields[1]);
        self::assertSame(3, $fields[11]);
        self::assertSame('peek', $fields[12]);
    }

    public function testCompletePromiseResolveCarriesValueInFieldTwo(): void
    {
        $command = CompletePromiseCommand::resolve('k', 'payload', 9, 'res');

        self::assertSame(MessageType::CompletePromiseCommand, $command->messageType());
        self::assertFalse($command->requestedAck());

        $fields = self::fields($command->encode());
        self::assertSame('k', $fields[1]);
        self::assertSame(9, $fields[11]);
        self::assertSame('res', $fields[12]);
        self::assertArrayNotHasKey(3, $fields, 'a resolved promise must not carry a failure');
        self::assertIsString($fields[2]);
        self::assertSame('payload', Value::decode($fields[2])->content);
    }

    public function testCompletePromiseRejectCarriesFailureInFieldThree(): void
    {
        $command = CompletePromiseCommand::reject('k', new Failure(13, 'boom'), 4);

        $fields = self::fields($command->encode());
        self::assertSame('k', $fields[1]);
        self::assertSame(4, $fields[11]);
        self::assertArrayNotHasKey(2, $fields, 'a rejected promise must not carry a value');

        self::assertIsString($fields[3]);
        $failure = Failure::decode($fields[3]);
        self::assertSame(13, $failure->code);
        self::assertSame('boom', $failure->message);
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
