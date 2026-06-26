<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Protocol\Message\Header;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

final class HeaderTest extends TestCase
{
    public function testRoundTripPreservesKeyAndValue(): void
    {
        $header = Header::decode((new Header('Content-Type', 'application/json'))->encode());

        self::assertSame('Content-Type', $header->key);
        self::assertSame('application/json', $header->value);
    }

    public function testKeyAndValueAreAlwaysPresentEvenWhenEmpty(): void
    {
        // Both fields use the presence-sensitive writer, so an all-empty header still
        // emits fields 1 and 2.
        $reader = new Reader((new Header('', ''))->encode());

        $seen = [];
        while (!$reader->atEnd()) {
            [$field] = $reader->readTag();
            $seen[] = $field;
            self::assertSame('', $reader->readLengthDelimited());
        }

        self::assertSame([1, 2], $seen);
    }

    public function testDecodeSkipsUnknownFields(): void
    {
        $bytes = (new Writer())
            ->writeStringPresent(1, 'X-Trace')
            ->writeUint32Present(9, 1)
            ->writeStringPresent(2, 'abc')
            ->toString();

        $header = Header::decode($bytes);

        self::assertSame('X-Trace', $header->key);
        self::assertSame('abc', $header->value);
    }
}
