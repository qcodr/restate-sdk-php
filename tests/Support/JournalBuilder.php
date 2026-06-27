<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support;

use Qcodr\Restate\Sdk\Protocol\MessageHeader;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * Builds the byte stream the runtime would send to the SDK (StartMessage + replayed
 * journal), so the state machine can be unit-tested without a live server.
 *
 * The SDK only ever decodes these frames, so the builder encodes them directly with
 * the protobuf {@see Writer} instead of going through the (outgoing-only) message VOs.
 *
 * @internal test support
 */
final class JournalBuilder
{
    /** @var list<array{0: MessageType, 1: string}> */
    private array $journal = [];

    /**
     * @param array<string, string> $stateMap
     */
    public function __construct(
        private readonly string $invocationId = 'inv-1',
        private readonly string $key = '',
        private readonly array $stateMap = [],
        private readonly bool $partialState = false,
        private readonly int $randomSeed = 0,
        private readonly ?string $idempotencyKey = null,
    ) {
    }

    /**
     * @param array<string, string> $headers request headers carried on the input command
     */
    public function input(string $body, array $headers = []): self
    {
        $writer = new Writer();
        foreach ($headers as $name => $value) {
            $header = (new Writer())
                ->writeStringPresent(1, (string) $name)
                ->writeStringPresent(2, $value)
                ->toString();
            $writer->writeMessage(1, $header);
        }

        $value = (new Writer())->writeBytes(1, $body)->toString();
        $payload = $writer->writeMessage(14, $value)->toString();
        $this->journal[] = [MessageType::InputCommand, $payload];

        return $this;
    }

    /** Adds a replayed command frame; only its presence and type matter to the VM. */
    public function command(MessageType $type, string $payload = ''): self
    {
        $this->journal[] = [$type, $payload];

        return $this;
    }

    public function sleepCompletion(int $completionId): self
    {
        $payload = (new Writer())
            ->writeUint32Present(1, $completionId)
            ->writeMessage(4, '') // Void
            ->toString();
        $this->journal[] = [MessageType::SleepCompletion, $payload];

        return $this;
    }

    public function lazyStateCompletion(int $completionId, ?string $value): self
    {
        $writer = (new Writer())->writeUint32Present(1, $completionId);
        if ($value === null) {
            $writer->writeMessage(4, ''); // Void
        } else {
            $writer->writeMessage(5, (new Writer())->writeBytes(1, $value)->toString()); // Value
        }
        $this->journal[] = [MessageType::GetLazyStateCompletion, $writer->toString()];

        return $this;
    }

    public function callCompletion(int $completionId, string $value): self
    {
        $payload = (new Writer())
            ->writeUint32Present(1, $completionId)
            ->writeMessage(5, (new Writer())->writeBytes(1, $value)->toString())
            ->toString();
        $this->journal[] = [MessageType::CallCompletion, $payload];

        return $this;
    }

    /**
     * Adds a CallCompletion carrying a {@see \Qcodr\Restate\Sdk\Protocol\Message\Failure}
     * (notification field 6) instead of a value, as the runtime delivers when the
     * callee terminally failed.
     */
    public function failedCallCompletion(int $completionId, string $message, int $code = 500): self
    {
        $failure = (new Writer())
            ->writeUint32(1, $code)
            ->writeString(2, $message)
            ->toString();
        $payload = (new Writer())
            ->writeUint32Present(1, $completionId)
            ->writeMessage(6, $failure)
            ->toString();
        $this->journal[] = [MessageType::CallCompletion, $payload];

        return $this;
    }

    public function invocationIdCompletion(int $completionId, string $invocationId): self
    {
        $payload = (new Writer())
            ->writeUint32Present(1, $completionId)
            ->writeStringPresent(16, $invocationId)
            ->toString();
        $this->journal[] = [MessageType::CallInvocationIdCompletion, $payload];

        return $this;
    }

    /**
     * Adds a built-in CANCEL signal notification (signal idx 1, void result), as the
     * runtime delivers when an invocation has been cancelled.
     */
    public function cancelSignal(): self
    {
        $payload = (new Writer())
            ->writeUint32Present(2, 1) // signal idx = BuiltInSignal.CANCEL
            ->writeMessage(4, '') // Void result
            ->toString();
        $this->journal[] = [MessageType::SignalNotification, $payload];

        return $this;
    }

    /**
     * Adds a signal notification carrying a value (notification field 5), as the runtime
     * delivers when an awakeable is resolved by another invocation. Awakeable signals
     * start at idx 17 (built-in signals reserve 0..16); mirrors {@see cancelSignal} but
     * with a value payload instead of a void cancel.
     */
    public function awakeableSignal(int $signalId, string $value): self
    {
        $payload = (new Writer())
            ->writeUint32Present(2, $signalId)
            ->writeMessage(5, (new Writer())->writeBytes(1, $value)->toString())
            ->toString();
        $this->journal[] = [MessageType::SignalNotification, $payload];

        return $this;
    }

    public function runCompletion(int $completionId, string $value): self
    {
        $payload = (new Writer())
            ->writeUint32Present(1, $completionId)
            ->writeMessage(5, (new Writer())->writeBytes(1, $value)->toString())
            ->toString();
        $this->journal[] = [MessageType::RunCompletion, $payload];

        return $this;
    }

    /**
     * Adds the control-frame ack the runtime sends in reply to a `ProposeRunCompletion`
     * (which sets REQUESTED_ACK). The SDK reads and ignores it — the resolved value
     * arrives separately as a {@see MessageType::RunCompletion} notification.
     */
    public function proposeRunCompletionAck(int $completionId): self
    {
        $payload = (new Writer())->writeUint32Present(1, $completionId)->toString();
        $this->journal[] = [MessageType::ProposeRunCompletionAck, $payload];

        return $this;
    }

    public function build(): string
    {
        $buffer = self::frame(MessageType::Start, $this->encodeStart(\count($this->journal)));

        return $buffer . $this->frames();
    }

    /**
     * Encodes the appended entries WITHOUT the leading StartMessage, so a streaming
     * test can feed completions/signals to an already-parsed state machine the way the
     * runtime streams them after the initial journal.
     */
    public function frames(): string
    {
        $buffer = '';
        foreach ($this->journal as [$type, $payload]) {
            $buffer .= self::frame($type, $payload);
        }

        return $buffer;
    }

    private function encodeStart(int $knownEntries): string
    {
        $writer = (new Writer())
            ->writeBytesPresent(1, $this->invocationId)
            ->writeUint32(3, $knownEntries);

        foreach ($this->stateMap as $key => $value) {
            $entry = (new Writer())
                ->writeBytesPresent(1, $key)
                ->writeBytesPresent(2, $value)
                ->toString();
            $writer->writeMessage(4, $entry);
        }

        $writer
            ->writeBool(5, $this->partialState)
            ->writeString(6, $this->key)
            ->writeUint64(9, $this->randomSeed);

        if ($this->idempotencyKey !== null) {
            $writer->writeStringPresent(12, $this->idempotencyKey);
        }

        return $writer->toString();
    }

    private static function frame(MessageType $type, string $payload): string
    {
        return (new MessageHeader($type->value, \strlen($payload)))->encode() . $payload;
    }
}
