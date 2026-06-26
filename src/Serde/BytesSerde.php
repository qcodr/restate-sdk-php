<?php

declare(strict_types=1);

namespace Restate\Sdk\Serde;

use Stringable;

/**
 * Raw octet-stream passthrough (de)serialization.
 *
 * Handler inputs/outputs and state values are carried verbatim as their raw bytes,
 * with no encoding applied. This mirrors the Rust SDK's raw `Vec<u8>`/`Bytes` serde
 * and backs the `application/octet-stream` content type.
 *
 * {@see serialize} accepts a `string` (or any {@see Stringable}) and returns its bytes
 * unchanged; {@see deserialize} returns the wire bytes verbatim.
 */
final class BytesSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new SerializationException(
            \sprintf('BytesSerde can only serialize a string or Stringable, got %s', \get_debug_type($value)),
        );
    }

    public function deserialize(string $bytes, ?string $type = null): mixed
    {
        return $bytes;
    }
}
