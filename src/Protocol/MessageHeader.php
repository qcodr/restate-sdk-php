<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol;

/**
 * The fixed 64-bit big-endian frame header that prefixes every protocol message.
 *
 *   bits 63..48  type      (16 bits)
 *   bit  47      A         REQUESTED_ACK flag (only set on ProposeRunCompletion)
 *   bits 46..32  reserved  (15 bits, must be zero)
 *   bits 31..0   length    payload byte length, excluding the header
 */
final class MessageHeader
{
    public const SIZE = 8;
    private const REQUESTED_ACK_FLAG = 0x0000_8000_0000_0000;
    private const TYPE_SHIFT = 48;
    private const LENGTH_MASK = 0xFFFF_FFFF;
    private const TYPE_MASK = 0xFFFF;

    public function __construct(
        public readonly int $typeCode,
        public readonly int $length,
        public readonly bool $requestedAck = false,
    ) {
    }

    public function encode(): string
    {
        $value = ($this->typeCode << self::TYPE_SHIFT) | $this->length;
        if ($this->requestedAck) {
            $value |= self::REQUESTED_ACK_FLAG;
        }

        return \pack('J', $value);
    }

    public static function decode(string $bytes): self
    {
        if (\strlen($bytes) < self::SIZE) {
            throw new ProtocolException('Truncated message header');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = \unpack('J', $bytes);
        $value = $unpacked[1];

        $typeCode = ($value >> self::TYPE_SHIFT) & self::TYPE_MASK;
        $length = $value & self::LENGTH_MASK;
        $requestedAck = ($value & self::REQUESTED_ACK_FLAG) !== 0;

        return new self($typeCode, $length, $requestedAck);
    }
}
