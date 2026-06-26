<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\WireType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * Nested `Value { bytes content = 1 }`.
 *
 * Distinguishing a present-but-empty value from an absent value matters in the
 * protocol, so {@see encode} always emits a parseable (possibly empty) payload.
 */
final class Value
{
    public function __construct(public readonly string $content)
    {
    }

    public function encode(): string
    {
        return (new Writer())->writeBytes(1, $this->content)->toString();
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $content = '';
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 1 && $wire === WireType::LENGTH_DELIMITED) {
                $content = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return new self($content);
    }
}
