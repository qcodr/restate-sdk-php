<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Protobuf;

use Qcodr\Restate\Sdk\Protocol\ProtocolException;

/**
 * Minimal proto3 binary decoder, the inverse of {@see Writer}.
 *
 * The decoder is field-oriented: callers loop over {@see readTag} and dispatch on
 * the field number, reading the payload with the matching typed accessor and
 * calling {@see skip} for unknown fields (forward compatibility).
 */
final class Reader
{
    private int $offset = 0;
    private readonly int $length;

    public function __construct(private readonly string $buffer)
    {
        $this->length = \strlen($buffer);
    }

    public function atEnd(): bool
    {
        return $this->offset >= $this->length;
    }

    /**
     * Reads the next field tag.
     *
     * @return array{0: int, 1: int} [fieldNumber, wireType]
     */
    public function readTag(): array
    {
        $tag = $this->readVarint();

        return [$tag >> 3, $tag & 0x07];
    }

    public function readVarint(): int
    {
        $result = 0;
        $shift = 0;
        do {
            if ($this->offset >= $this->length) {
                throw new ProtocolException('Unexpected end of buffer while reading varint');
            }
            if ($shift > 63) {
                throw new ProtocolException('Varint is too long');
            }
            $byte = \ord($this->buffer[$this->offset]);
            $this->offset++;
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $result;
    }

    public function readLengthDelimited(): string
    {
        $len = $this->readVarint();
        // A 10-byte varint with bit 63 set decodes to a negative PHP int; reject it
        // so it cannot bypass the bounds check and corrupt the offset (DoS).
        if ($len < 0 || $this->offset + $len > $this->length) {
            throw new ProtocolException('Length-delimited field exceeds buffer');
        }
        $value = \substr($this->buffer, $this->offset, $len);
        $this->offset += $len;

        return $value;
    }

    /** Skips a field whose value is not consumed by the caller. */
    public function skip(int $wireType): void
    {
        switch ($wireType) {
            case WireType::VARINT:
                $this->readVarint();
                break;
            case WireType::FIXED64:
                $this->advance(8);
                break;
            case WireType::LENGTH_DELIMITED:
                $this->advance($this->readVarint());
                break;
            case WireType::FIXED32:
                $this->advance(4);
                break;
            default:
                throw new ProtocolException("Cannot skip unknown wire type {$wireType}");
        }
    }

    private function advance(int $bytes): void
    {
        if ($bytes < 0 || $this->offset + $bytes > $this->length) {
            throw new ProtocolException('Attempted to read past end of buffer');
        }
        $this->offset += $bytes;
    }
}
