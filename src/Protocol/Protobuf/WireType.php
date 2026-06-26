<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Protobuf;

/**
 * Protobuf wire types as defined by the protobuf binary encoding.
 *
 * @see https://protobuf.dev/programming-guides/encoding/
 */
final class WireType
{
    public const VARINT = 0;
    public const FIXED64 = 1;
    public const LENGTH_DELIMITED = 2;
    public const FIXED32 = 5;

    private function __construct()
    {
    }
}
