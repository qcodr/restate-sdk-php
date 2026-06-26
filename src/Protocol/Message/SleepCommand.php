<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `SleepCommandMessage` (0x040C): durable timer. Completable — the runtime delivers
 * a `SleepCompletionNotification` carrying `void` once the wake-up time elapses.
 */
final class SleepCommand implements OutgoingMessage
{
    public function __construct(
        public readonly int $wakeUpTimeMillis,
        public readonly int $resultCompletionId,
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::SleepCommand;
    }

    public function encode(): string
    {
        return (new Writer())
            ->writeUint64(1, $this->wakeUpTimeMillis)
            ->writeUint32(11, $this->resultCompletionId)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
