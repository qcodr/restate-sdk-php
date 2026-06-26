<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `SuspensionMessage` (0x0001): sent to suspend an invocation that is awaiting
 * notifications which have not arrived. Carries the await point as a {@see Future}.
 */
final class SuspensionMessage implements OutgoingMessage
{
    public function __construct(public readonly Future $awaitingOn)
    {
    }

    public function messageType(): MessageType
    {
        return MessageType::Suspension;
    }

    public function encode(): string
    {
        return (new Writer())->writeMessage(4, $this->awaitingOn->encode())->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
