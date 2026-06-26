<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\StartMessage;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

final class StartMessageTest extends TestCase
{
    public function testDecodesMetadataAndEagerState(): void
    {
        $stateEntry = (new Writer())
            ->writeBytesPresent(1, 'count')
            ->writeBytesPresent(2, '5')
            ->toString();

        $payload = (new Writer())
            ->writeBytesPresent(1, 'inv-1')
            ->writeStringPresent(2, 'dbg-1')
            ->writeUint32(3, 2)
            ->writeMessage(4, $stateEntry)
            ->writeBool(5, true)
            ->writeStringPresent(6, 'mykey')
            ->writeUint64(9, 987654321)
            ->writeStringPresent(12, 'idem-1')
            ->toString();

        $start = StartMessage::decode($payload);

        self::assertSame('inv-1', $start->id);
        self::assertSame('dbg-1', $start->debugId);
        self::assertSame(2, $start->knownEntries);
        self::assertSame(['count' => '5'], $start->stateMap);
        self::assertTrue($start->partialState);
        self::assertSame('mykey', $start->key);
        self::assertSame(987654321, $start->randomSeed);
        self::assertSame('idem-1', $start->idempotencyKey);
    }

    public function testDecodesMinimalStart(): void
    {
        $payload = (new Writer())
            ->writeBytesPresent(1, 'inv-2')
            ->writeUint32(3, 1)
            ->toString();

        $start = StartMessage::decode($payload);

        self::assertSame('inv-2', $start->id);
        self::assertSame(1, $start->knownEntries);
        self::assertSame([], $start->stateMap);
        self::assertFalse($start->partialState);
        self::assertSame('', $start->key);
        self::assertNull($start->idempotencyKey);
    }
}
