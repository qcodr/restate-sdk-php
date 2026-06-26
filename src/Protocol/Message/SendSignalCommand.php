<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `SendSignalCommandMessage` (0x0410): delivers a signal to another invocation,
 * addressed by its invocation id. The signal is identified either by its built-in
 * index (`idx`) or by a custom `name`, and carries a result (void/value/failure).
 *
 * The {@see cancel} factory builds the cancellation signal: built-in CANCEL index 1
 * with a void result.
 */
final class SendSignalCommand implements OutgoingMessage
{
    /** Built-in CANCEL signal index (BuiltInSignal.CANCEL). */
    public const CANCEL_SIGNAL_INDEX = 1;

    private function __construct(
        public readonly string $targetInvocationId,
        public readonly ?int $signalIdx,
        public readonly ?string $signalName,
        public readonly bool $void,
        public readonly string $name = '',
    ) {
    }

    /** Cancels the target invocation by sending it the built-in CANCEL signal. */
    public static function cancel(string $targetInvocationId): self
    {
        return new self($targetInvocationId, self::CANCEL_SIGNAL_INDEX, null, true);
    }

    public function messageType(): MessageType
    {
        return MessageType::SendSignalCommand;
    }

    public function encode(): string
    {
        $writer = (new Writer())->writeStringPresent(1, $this->targetInvocationId);

        if ($this->signalName !== null) {
            $writer->writeStringPresent(3, $this->signalName);
        } else {
            $writer->writeUint32Present(2, $this->signalIdx ?? 0);
        }

        if ($this->void) {
            $writer->writeMessage(4, ''); // Void result
        }

        return $writer->writeString(12, $this->name)->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
