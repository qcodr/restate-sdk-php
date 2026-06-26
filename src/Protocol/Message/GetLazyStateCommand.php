<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `GetLazyStateCommandMessage` (0x0402): read a state key whose value is not in the
 * eager state map. Completable — the runtime delivers a
 * `GetLazyStateCompletionNotification` carrying `void` (absent) or `value`.
 */
final class GetLazyStateCommand implements OutgoingMessage
{
    public function __construct(
        public readonly string $key,
        public readonly int $resultCompletionId,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::GetLazyStateCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeBytesPresent(1, $this->key)
            ->writeUint32(11, $this->resultCompletionId)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
