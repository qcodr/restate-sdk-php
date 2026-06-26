<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `OneWayCallCommandMessage` (0x040E): fire-and-forget send to another handler,
 * optionally delayed via `invokeTimeMillis` (ms since epoch, 0 = as soon as
 * possible). Yields a single `CallInvocationIdCompletionNotification`.
 */
final class OneWayCallCommand implements OutgoingMessage
{
    /** @param list<Header> $headers */
    public function __construct(
        public readonly string $serviceName,
        public readonly string $handlerName,
        public readonly string $parameter,
        public readonly int $invocationIdNotificationIdx,
        public readonly int $invokeTimeMillis = 0,
        public readonly string $key = '',
        public readonly ?string $idempotencyKey = null,
        public readonly array $headers = [],
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::OneWayCallCommand;
    }

    public function encode(): string
    {
        $writer = (new Writer())
            ->writeStringPresent(1, $this->serviceName)
            ->writeStringPresent(2, $this->handlerName)
            ->writeBytes(3, $this->parameter)
            ->writeUint64(4, $this->invokeTimeMillis);

        foreach ($this->headers as $header) {
            $writer->writeMessage(5, $header->encode());
        }

        $writer->writeString(6, $this->key);
        if ($this->idempotencyKey !== null && $this->idempotencyKey !== '') {
            $writer->writeStringPresent(7, $this->idempotencyKey);
        }

        return $writer
            ->writeUint32(10, $this->invocationIdNotificationIdx)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
