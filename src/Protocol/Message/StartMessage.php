<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\Protobuf\Reader;

/**
 * `StartMessage` (0x0000): the first frame of every invocation stream. It carries
 * the invocation metadata and the eager state map used to serve state reads
 * without a runtime round-trip.
 *
 * Incoming only — the SDK never produces this message.
 */
final class StartMessage
{
    /**
     * @param array<string, string> $stateMap eager key/value state, keys as raw bytes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $debugId,
        public readonly int $knownEntries,
        public readonly array $stateMap,
        public readonly bool $partialState,
        public readonly string $key,
        public readonly int $randomSeed,
        public readonly ?string $idempotencyKey,
        public readonly int $retryCount = 0,
    ) {
    }

    public static function decode(string $bytes): self
    {
        $reader = new Reader($bytes);
        $id = '';
        $debugId = '';
        $knownEntries = 0;
        $stateMap = [];
        $partialState = false;
        $key = '';
        $randomSeed = 0;
        $idempotencyKey = null;
        $retryCount = 0;

        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $id = $reader->readLengthDelimited();
                    break;
                case 2:
                    $debugId = $reader->readLengthDelimited();
                    break;
                case 3:
                    $knownEntries = $reader->readVarint();
                    break;
                case 4:
                    [$entryKey, $entryValue] = self::decodeStateEntry($reader->readLengthDelimited());
                    $stateMap[$entryKey] = $entryValue;
                    break;
                case 5:
                    $partialState = $reader->readVarint() !== 0;
                    break;
                case 6:
                    $key = $reader->readLengthDelimited();
                    break;
                case 7:
                    $retryCount = $reader->readVarint();
                    break;
                case 9:
                    $randomSeed = $reader->readVarint();
                    break;
                case 12:
                    $idempotencyKey = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        return new self(
            $id,
            $debugId,
            $knownEntries,
            $stateMap,
            $partialState,
            $key,
            $randomSeed,
            $idempotencyKey,
            $retryCount,
        );
    }

    /**
     * @return array{0: string, 1: string} [key, value]
     */
    private static function decodeStateEntry(string $bytes): array
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

        return [$key, $value];
    }
}
