<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\StartMessage;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

final class StartMessageDecodeSkipTest extends TestCase
{
    public function testDecodesRetryCountAndSkipsUnknownTopLevelFields(): void
    {
        $payload = (new Writer())
            ->writeBytesPresent(1, 'inv-9')
            ->writeUint32(3, 4)
            ->writeUint32Present(7, 11)
            ->writeUint32Present(99, 1)
            ->toString();

        $start = StartMessage::decode($payload);

        self::assertSame('inv-9', $start->id);
        self::assertSame(4, $start->knownEntries);
        self::assertSame(11, $start->retryCount);
    }

    public function testStateEntryDecoderSkipsUnknownNestedFields(): void
    {
        // A state entry may grow new fields in a future protocol; the nested decoder
        // must skip the unknown field (9) and still read key (1) and value (2).
        $stateEntry = (new Writer())
            ->writeBytesPresent(1, 'count')
            ->writeUint32Present(9, 7)
            ->writeBytesPresent(2, '5')
            ->toString();

        $payload = (new Writer())
            ->writeBytesPresent(1, 'inv-10')
            ->writeUint32(3, 1)
            ->writeMessage(4, $stateEntry)
            ->toString();

        $start = StartMessage::decode($payload);

        self::assertSame(['count' => '5'], $start->stateMap);
    }

    public function testRetryCountDefaultsToZeroWhenAbsent(): void
    {
        $payload = (new Writer())
            ->writeBytesPresent(1, 'inv-11')
            ->writeUint32(3, 1)
            ->toString();

        self::assertSame(0, StartMessage::decode($payload)->retryCount);
    }
}
