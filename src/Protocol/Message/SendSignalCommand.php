<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * `SendSignalCommandMessage` (0x0410): delivers a signal to another invocation,
 * addressed by its invocation id. The signal is identified either by its built-in
 * index (`idx`) or by a custom `name`, and carries a result (void/value/failure).
 *
 * The {@see cancel} factory builds the cancellation signal: built-in CANCEL index 1
 * with a void result. The {@see resolveNamed} / {@see rejectNamed} factories deliver
 * a custom-named signal carrying a value or a failure — the send side of named
 * signals (the receive side awaits via {@see Future::forNamedSignal}).
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
        public readonly ?Value $value = null,
        public readonly ?Failure $failure = null,
    ) {
    }

    /** Cancels the target invocation by sending it the built-in CANCEL signal. */
    public static function cancel(string $targetInvocationId): self
    {
        return new self($targetInvocationId, self::CANCEL_SIGNAL_INDEX, null, true);
    }

    /** Resolves a custom-named signal on the target invocation with a value. */
    public static function resolveNamed(string $targetInvocationId, string $signalName, Value $value): self
    {
        return new self($targetInvocationId, null, $signalName, false, '', $value, null);
    }

    /** Rejects a custom-named signal on the target invocation with a terminal failure. */
    public static function rejectNamed(string $targetInvocationId, string $signalName, Failure $failure): self
    {
        return new self($targetInvocationId, null, $signalName, false, '', null, $failure);
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

        if ($this->value !== null) {
            $writer->writeMessage(5, $this->value->encode()); // Value result
        } elseif ($this->failure !== null) {
            $writer->writeMessage(6, $this->failure->encode()); // Failure result
        } elseif ($this->void) {
            $writer->writeMessage(4, ''); // Void result
        }

        return $writer->writeString(12, $this->name)->toString();
    }

    public function requestedAck(): bool
    {
        return false;
    }
}
