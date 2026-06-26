<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `GetPromiseCommandMessage` (0x0409): await a workflow durable promise by name.
 * Completable — resolves with the promise value or failure once it is completed.
 */
final class GetPromiseCommand implements OutgoingMessage
{
    public function __construct(
        public readonly string $key,
        public readonly int $resultCompletionId,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::GetPromiseCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeStringPresent(1, $this->key)
            ->writeUint32(11, $this->resultCompletionId)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
