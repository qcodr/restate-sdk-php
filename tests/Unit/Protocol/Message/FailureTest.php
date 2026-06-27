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

    public function testEncodeDecodeRoundTripsMetadata(): void
    {
        // The repeated `metadata` field (3) carries user error context (V7) and must
        // survive an encode/decode round trip alongside code (1) and message (2).
        $failure = new Failure(42, 'oops', ['key1' => 'v1', 'key2' => 'v2']);

        $decoded = Failure::decode($failure->encode());

        self::assertSame(42, $decoded->code);
        self::assertSame('oops', $decoded->message);
        self::assertSame(['key1' => 'v1', 'key2' => 'v2'], $decoded->metadata);
    }

    public function testDecodeToleratesAnUnknownTrailingField(): void
    {
        // An unknown field (here field 5) is skipped; code/message still decode and
        // metadata stays empty.
        $bytes = (new Writer())
            ->writeUint32(1, 42)
            ->writeString(2, 'oops')
            ->writeBytesPresent(5, 'future-field')
            ->toString();

        $failure = Failure::decode($bytes);

        self::assertSame(42, $failure->code);
        self::assertSame('oops', $failure->message);
        self::assertSame([], $failure->metadata);
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
