<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Protocol\Message\StateKeys;
use Restate\Sdk\Protocol\Protobuf\Writer;

final class StateKeysTest extends TestCase
{
    public function testRoundTripPreservesKeyOrder(): void
    {
        $keys = ['alpha', 'beta', 'gamma'];

        $decoded = StateKeys::decode((new StateKeys($keys))->encode());

        self::assertSame($keys, $decoded->keys);
    }

    public function testEmptyKeyListEncodesToEmptyAndDecodesBack(): void
    {
        self::assertSame('', (new StateKeys([]))->encode());
        self::assertSame([], StateKeys::decode('')->keys);
    }

    public function testDecodeSkipsUnknownFields(): void
    {
        // A forward-compatible payload: the known repeated key field (1) plus an
        // unknown scalar field (5) that a newer runtime might add.
        $bytes = (new Writer())
            ->writeBytesPresent(1, 'k1')
            ->writeUint32Present(5, 42)
            ->writeBytesPresent(1, 'k2')
            ->toString();

        self::assertSame(['k1', 'k2'], StateKeys::decode($bytes)->keys);
    }
}
