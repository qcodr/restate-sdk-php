<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `ProposeRunCompletionMessage` (0x0005): proposes the result of a `ctx.run` to the
 * runtime for durable storage. This is the only message that sets the REQUESTED_ACK
 * frame flag; the runtime replies with a `ProposeRunCompletionAck` (or, if ack were
 * not requested, the full `RunCompletionNotification`).
 *
 * Note: the success result is a raw `bytes value = 14` (NOT wrapped in `Value`).
 */
final class ProposeRunCompletion implements OutgoingMessage
{
    private function __construct(
        public readonly int $resultCompletionId,
        public readonly ?string $value,
        public readonly ?Failure $failure,
    ) {
    }

    public static function success(int $resultCompletionId, string $value): self
    {
        return new self($resultCompletionId, $value, null);
    }

    public static function failure(int $resultCompletionId, Failure $failure): self
    {
        return new self($resultCompletionId, null, $failure);
    }

    public function messageType(): MessageType
    {
        return MessageType::ProposeRunCompletion;
    }

    public function encode(): string
    {
        $writer = (new Writer())->writeUint32(1, $this->resultCompletionId);
        if ($this->value !== null) {
            $writer->writeBytesPresent(14, $this->value);
        } elseif ($this->failure !== null) {
            $writer->writeMessage(15, $this->failure->encode());
        }

        return $writer->toString();
    }

    public function requestedAck(): bool
    {
        return true;
    }
}
