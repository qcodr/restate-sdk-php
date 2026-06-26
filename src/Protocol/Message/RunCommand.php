<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `RunCommandMessage` (0x0411): marks a durable side-effect boundary. The result is
 * proposed separately via {@see ProposeRunCompletion} and delivered back as a
 * `RunCompletionNotification` carrying `resultCompletionId`.
 */
final class RunCommand implements OutgoingMessage
{
    public function __construct(
        public readonly int $resultCompletionId,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::RunCommand;
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
