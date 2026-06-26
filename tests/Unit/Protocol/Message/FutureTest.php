<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\CombinatorType;
use Qcodr\Restate\Sdk\Protocol\Message\Future;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;

final class FutureTest extends TestCase
{
    public function testForSignalEncodesPackedSignalIdInFieldTwo(): void
    {
        // A lone signal await: the signal id is packed into field 2 and the default
        // (Unknown = 0) combinator is omitted by proto3 scalar rules.
        $reader = new Reader(Future::forSignal(8)->encode());

        [$field, $wire] = $reader->readTag();
        self::assertSame(2, $field);
        self::assertSame(WireType::LENGTH_DELIMITED, $wire);
        self::assertSame(8, (new Reader($reader->readLengthDelimited()))->readVarint());
        self::assertTrue($reader->atEnd(), 'the default combinator must not be emitted');
    }

    public function testForCompletionEncodesPackedCompletionInFieldOne(): void
    {
        $reader = new Reader(Future::forCompletion(42)->encode());

        [$field, $wire] = $reader->readTag();
        self::assertSame(1, $field);
        self::assertSame(WireType::LENGTH_DELIMITED, $wire);
        self::assertSame(42, (new Reader($reader->readLengthDelimited()))->readVarint());
    }

    public function testEncodesNamedSignalsNestedFuturesAndCombinator(): void
    {
        $future = new Future(
            waitingNamedSignals: ['ns-a', 'ns-b'],
            nestedFutures: [Future::forCompletion(3)],
            combinatorType: CombinatorType::AllCompleted,
        );

        $reader = new Reader($future->encode());
        $namedSignals = [];
        $nested = null;
        $combinator = null;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 3:
                    $namedSignals[] = $reader->readLengthDelimited();
                    break;
                case 4:
                    $nested = $reader->readLengthDelimited();
                    break;
                case 5:
                    $combinator = $reader->readVarint();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        self::assertSame(['ns-a', 'ns-b'], $namedSignals);
        self::assertSame(CombinatorType::AllCompleted->value, $combinator);

        // The nested future is a complete sub-message: its completion id is packed
        // under field 1, exactly like a top-level completion await.
        self::assertIsString($nested);
        $nestedReader = new Reader($nested);
        [$nestedField] = $nestedReader->readTag();
        self::assertSame(1, $nestedField);
        self::assertSame(3, (new Reader($nestedReader->readLengthDelimited()))->readVarint());
    }
}
