<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `ClearStateCommandMessage` (0x0404): clear a single state key. Non-completable.
 */
final class ClearStateCommand implements OutgoingMessage
{
    public function __construct(
        public readonly string $key,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::ClearStateCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeBytesPresent(1, $this->key)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
