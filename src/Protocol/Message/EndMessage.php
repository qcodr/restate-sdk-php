<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;

/**
 * `EndMessage` (0x0003): terminates an invocation that returned a value or a
 * terminal failure. The journal is closed; the runtime does not retry.
 */
final class EndMessage implements OutgoingMessage
{
    public function messageType(): MessageType
    {
        return MessageType::End;
    }

    public function encode(): string
    {
        return '';
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
