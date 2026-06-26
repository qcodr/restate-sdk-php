<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * Nested `Header { string key = 1; string value = 2; }` carrying request headers
 * on the input command and on outgoing calls.
 */
final class Header
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeStringPresent(1, $this->key)
            ->writeStringPresent(2, $this->value)
            ->toString();
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $key = '';
        $value = '';
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $key = $reader->readLengthDelimited();
                    break;
                case 2:
                    $value = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return new self($key, $value);
    }
}
