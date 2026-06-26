<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `CallCommandMessage` (0x040D): request-response call to another handler.
 *
 * Completable with two notifications: a `CallInvocationIdCompletionNotification`
 * (the callee's invocation id, field `invocationIdNotificationIdx`) and a
 * `CallCompletionNotification` (the result, field `resultCompletionId`).
 */
final class CallCommand implements OutgoingMessage
{
    /** @param list<Header> $headers */
    public function __construct(
        public readonly string $serviceName,
        public readonly string $handlerName,
        public readonly string $parameter,
        public readonly int $invocationIdNotificationIdx,
        public readonly int $resultCompletionId,
        public readonly string $key = '',
        public readonly ?string $idempotencyKey = null,
        public readonly array $headers = [],
        public readonly string $name = '',
    ) {
    }

    public function messageType(): MessageType
    {
        return MessageType::CallCommand;
    }

    public function encode(): string
    {
        $writer = (new Writer())
            ->writeStringPresent(1, $this->serviceName)
            ->writeStringPresent(2, $this->handlerName)
            ->writeBytes(3, $this->parameter);

        foreach ($this->headers as $header) {
            $writer->writeMessage(4, $header->encode());
        }

        $writer->writeString(5, $this->key);
        if ($this->idempotencyKey !== null && $this->idempotencyKey !== '') {
            $writer->writeStringPresent(6, $this->idempotencyKey);
        }

        return $writer
            ->writeUint32(10, $this->invocationIdNotificationIdx)
            ->writeUint32(11, $this->resultCompletionId)
            ->writeString(12, $this->name)
            ->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
