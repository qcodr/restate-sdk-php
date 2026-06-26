<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `ClearAllStateCommandMessage` (0x0405): clear every state key. Non-completable.
 */
final class ClearAllStateCommand implements OutgoingMessage
{
    public function __construct(public readonly string $name = '')
    {
    }

    public function messageType(): MessageType
    {
        return MessageType::ClearAllStateCommand;
    }

    public function encode(): string
    {
        return (new Writer())->writeString(12, $this->name)->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
