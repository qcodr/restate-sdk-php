<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * Nested `Failure { uint32 code = 1; string message = 2; }`.
 *
 * Carries user-visible terminal failures (an invocation's failure result or a
 * failed call result). The repeated `metadata` field (3) is decode-tolerant but
 * not produced by this SDK.
 */
final class Failure
{
    public function __construct(
        public readonly int $code,
        public readonly string $message,
    ) {
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeUint32(1, $this->code)
            ->writeString(2, $this->message)
            ->toString();
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $code = 0;
        $message = '';
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $code = $reader->readVarint();
                    break;
                case 2:
                    $message = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return new self($code, $message);
    }
}
