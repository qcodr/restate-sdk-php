<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * Nested `Failure { uint32 code = 1; string message = 2; repeated FailureMetadata
 * metadata = 3; }` with `FailureMetadata { string key = 1; string value = 2; }`.
 *
 * Carries user-visible terminal failures (an invocation's failure result or a failed
 * call result). The `metadata` map is round-tripped so user error context propagates
 * across calls and to the caller (service protocol V7).
 */
final class Failure
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly array $metadata = [],
    ) {
    }

    public function encode(): string
    {
        $writer = (new Writer())
            ->writeUint32(1, $this->code)
            ->writeString(2, $this->message);

        foreach ($this->metadata as $key => $value) {
            $entry = (new Writer())
                ->writeString(1, (string) $key)
                ->writeString(2, $value)
                ->toString();
            $writer->writeMessage(3, $entry);
        }

        return $writer->toString();
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $code = 0;
        $message = '';
        $metadata = [];
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $code = $reader->readVarint();
                    break;
                case 2:
                    $message = $reader->readLengthDelimited();
                    break;
                case 3:
                    [$key, $value] = self::decodeMetadataEntry($reader->readLengthDelimited());
                    $metadata[$key] = $value;
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return new self($code, $message, $metadata);
    }

    /**
     * @return array{0: string, 1: string} [key, value]
     */
    private static function decodeMetadataEntry(string $bytes): array
    {
        $reader = new Reader($bytes);
        $key = '';
        $value = '';
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 1) {
                $key = $reader->readLengthDelimited();
            } elseif ($field === 2) {
                $value = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }

        return [$key, $value];
    }
}
