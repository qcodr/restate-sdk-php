<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Protobuf;

/**
 * Minimal proto3 binary encoder.
 *
 * Only the wire features used by the Restate service protocol are implemented:
 * varint (uint32/uint64/bool/enum), length-delimited (string/bytes/message) and
 * repeated fields. Following proto3 semantics, scalar fields equal to their
 * default value are omitted; callers that need explicit presence (oneof, optional,
 * empty-but-present messages) use the dedicated {@see writeMessage} / *Present helpers.
 */
final class Writer
{
    private string $buffer = '';

    public function toString(): string
    {
        return $this->buffer;
    }

    public function writeUint32(int $field, int $value): self
    {
        if ($value !== 0) {
            $this->writeUint32Present($field, $value);
        }

        return $this;
    }

    public function writeUint32Present(int $field, int $value): self
    {
        $this->buffer .= self::varint(self::tag($field, WireType::VARINT));
        $this->buffer .= self::varint($value);

        return $this;
    }

    public function writeUint64(int $field, int $value): self
    {
        if ($value !== 0) {
            $this->buffer .= self::varint(self::tag($field, WireType::VARINT));
            $this->buffer .= self::varint($value);
        }

        return $this;
    }

    public function writeBool(int $field, bool $value): self
    {
        if ($value) {
            $this->buffer .= self::varint(self::tag($field, WireType::VARINT));
            $this->buffer .= self::varint(1);
        }

        return $this;
    }

    public function writeString(int $field, string $value): self
    {
        if ($value !== '') {
            $this->writeLengthDelimited($field, $value);
        }

        return $this;
    }

    /** Always emits the field, even when the string is empty (presence-sensitive). */
    public function writeStringPresent(int $field, string $value): self
    {
        return $this->writeLengthDelimited($field, $value);
    }

    public function writeBytes(int $field, string $value): self
    {
        if ($value !== '') {
            $this->writeLengthDelimited($field, $value);
        }

        return $this;
    }

    public function writeBytesPresent(int $field, string $value): self
    {
        return $this->writeLengthDelimited($field, $value);
    }

    /** Writes a nested message, always present (even when its encoding is empty). */
    public function writeMessage(int $field, string $encoded): self
    {
        return $this->writeLengthDelimited($field, $encoded);
    }

    private function writeLengthDelimited(int $field, string $value): self
    {
        $this->buffer .= self::varint(self::tag($field, WireType::LENGTH_DELIMITED));
        $this->buffer .= self::varint(\strlen($value));
        $this->buffer .= $value;

        return $this;
    }

    public static function tag(int $field, int $wireType): int
    {
        return ($field << 3) | $wireType;
    }

    /**
     * Encodes a non-negative integer as a base-128 varint (little-endian groups).
     */
    public static function varint(int $value): string
    {
        if ($value < 0) {
            // Reinterpret as unsigned 64-bit: emit the full ten-byte form preserving bit pattern.
            return self::varintUnsignedNegative($value);
        }

        $out = '';
        while ($value > 0x7F) {
            $out .= \chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $out .= \chr($value & 0x7F);

        return $out;
    }

    private static function varintUnsignedNegative(int $value): string
    {
        // Logical right shift by 7: arithmetic >> sign-extends, so mask off the top
        // 7 bits it would otherwise fill. (1 << 57) - 1 keeps exactly bits 56..0.
        $logicalShiftMask = (1 << 57) - 1;

        $out = '';
        for ($i = 0; $i < 9; $i++) {
            $byte = $value & 0x7F;
            $value = ($value >> 7) & $logicalShiftMask;
            if ($value === 0) {
                $out .= \chr($byte);

                return $out;
            }
            $out .= \chr($byte | 0x80);
        }
        $out .= \chr($value & 0x7F);

        return $out;
    }
}
