<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `PeekPromiseCommandMessage` (0x040A): read a workflow durable promise without
 * blocking. Completable — resolves with void (not yet completed), a value, or a
 * failure.
 */
final class PeekPromiseCommand implements OutgoingMessage
{
    public function __construct(
        public readonly string $key,
        public readonly int $resultCompletionId,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::PeekPromiseCommand;
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
