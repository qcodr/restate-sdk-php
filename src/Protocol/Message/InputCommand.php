<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\Protobuf\Reader;

/**
 * `InputCommandMessage` (0x0400): the first journal entry, carrying the invocation
 * input body and request headers. Incoming only (consumed from the replayed journal).
 */
final class InputCommand
{
    /**
     * @param list<Header> $headers
     */
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $headers = [];
        $body = '';
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $headers[] = Header::decode($reader->readLengthDelimited());
                    break;
                case 14:
                    $body = Value::decode($reader->readLengthDelimited())->content;
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return new self($body, $headers);
    }
}
