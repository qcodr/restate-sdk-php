<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\WireType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * Nested `StateKeys { repeated bytes keys = 1 }`: the set of state keys known to
 * an object/workflow, inlined on eager reads and delivered on lazy completions.
 */
final class StateKeys
{
    /** @param list<string> $keys */
    public function __construct(public readonly array $keys)
    {
    }

    public function encode(): string
    {
        $writer = new Writer();
        foreach ($this->keys as $key) {
            $writer->writeBytesPresent(1, $key);
        }

        return $writer->toString();
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $keys = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 1 && $wire === WireType::LENGTH_DELIMITED) {
                $keys[] = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return new self($keys);
    }
}
