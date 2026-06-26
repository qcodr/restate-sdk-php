<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `GetEagerStateKeysCommandMessage` (0x0408): list state keys with the result
 * inlined from the eager state map. Non-completable.
 */
final class GetEagerStateKeysCommand implements OutgoingMessage
{
    /** @param list<string> $keys */
    public function __construct(
        public readonly array $keys,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::GetEagerStateKeysCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeMessage(14, (new StateKeys($this->keys))->encode())
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
