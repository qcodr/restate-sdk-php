<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\Value;
use Restate\Sdk\Protocol\Protobuf\Writer;

final class ValueTest extends TestCase
{
    public function testDecodeReadsContentAndSkipsForeignFields(): void
    {
        // A forward-compatible payload: an unknown scalar field (2) precedes the
        // content field (1). The decoder must skip the unknown field and still read
        // the content from field 1.
        $bytes = (new Writer())
            ->writeUint32Present(2, 99)
            ->writeBytesPresent(1, 'body')
            ->toString();

        self::assertSame('body', Value::decode($bytes)->content);
    }

    public function testDecodeIgnoresContentOnTheWrongWireType(): void
    {
        // Field 1 carried as a varint (wrong wire type) must be skipped rather than
        // read as content, leaving the default empty content.
        $bytes = (new Writer())->writeUint32Present(1, 7)->toString();

        self::assertSame('', Value::decode($bytes)->content);
    }

    public function testRoundTripPreservesContent(): void
    {
        self::assertSame('payload', Value::decode((new Value('payload'))->encode())->content);
        // Empty content collapses to an empty payload that still decodes back to "".
        self::assertSame('', (new Value(''))->encode());
        self::assertSame('', Value::decode('')->content);
    }
}
