<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `GetLazyStateKeysCommandMessage` (0x0406): list state keys when the eager state
 * map is partial. Completable — delivered as a
 * `GetLazyStateKeysCompletionNotification` carrying `state_keys`.
 */
final class GetLazyStateKeysCommand implements OutgoingMessage
{
    public function __construct(
        public readonly int $resultCompletionId,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::GetLazyStateKeysCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeUint32(11, $this->resultCompletionId)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
