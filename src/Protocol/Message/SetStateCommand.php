<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `SetStateCommandMessage` (0x0403): set a state key. Non-completable.
 */
final class SetStateCommand implements OutgoingMessage
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::SetStateCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeBytesPresent(1, $this->key)
            ->writeMessage(3, (new Value($this->value))->encode())
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
