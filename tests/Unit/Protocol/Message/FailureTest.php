<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

final class FailureTest extends TestCase
{
    public function testRoundTripPreservesCodeAndMessage(): void
    {
        $failure = Failure::decode((new Failure(13, 'boom'))->encode());

        self::assertSame(13, $failure->code);
        self::assertSame('boom', $failure->message);
    }

    public function testDecodeSkipsTheUnusedMetadataField(): void
    {
        // The repeated `metadata` field (3) is decode-tolerant but never produced by
        // this SDK; the decoder must skip it and still read code (1) and message (2).
        $bytes = (new Writer())
            ->writeUint32(1, 42)
            ->writeString(2, 'oops')
            ->writeBytesPresent(3, 'extra-metadata')
            ->toString();

        $failure = Failure::decode($bytes);

        self::assertSame(42, $failure->code);
        self::assertSame('oops', $failure->message);
    }

    public function testDefaultCodeDecodesToZero(): void
    {
        // code 0 is a proto3 default and is omitted on the wire; decoding the bare
        // message must restore the zero code.
        $failure = Failure::decode((new Failure(0, 'no code'))->encode());

        self::assertSame(0, $failure->code);
        self::assertSame('no code', $failure->message);
    }
}
