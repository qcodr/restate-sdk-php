<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol;

/**
 * A decoded protocol frame: its 16-bit type code and raw protobuf payload.
 *
 * The type code is kept as an int (not a {@see MessageType}) so unknown/custom
 * frames can be carried and skipped without failing the whole stream.
 */
final class Frame
{
    public function __construct(
        public readonly int $typeCode,
        public readonly string $payload,
        public readonly bool $requestedAck = false,
    ) {
    }

    public function type(): ?MessageType
    {
        return MessageType::tryFrom($this->typeCode);
    }
}
