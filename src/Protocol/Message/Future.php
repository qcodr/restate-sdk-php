<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

use Qcodr\Restate\Sdk\Protocol\Protobuf\Writer;

/**
 * The `Future` await-point tree sent inside a {@see SuspensionMessage}.
 *
 * Leaves are the notification ids the node waits for (completions, signal indexes,
 * named signals); inner nodes combine children via {@see CombinatorType}. A single
 * awaited completion is the common case: one completion id, default combinator.
 */
final class Future
{
    /**
     * @param list<int>    $waitingCompletions
     * @param list<int>    $waitingSignals
     * @param list<string> $waitingNamedSignals
     * @param list<Future> $nestedFutures
     */
    public function __construct(
        public readonly array $waitingCompletions = [],
        public readonly array $waitingSignals = [],
        public readonly array $waitingNamedSignals = [],
        public readonly array $nestedFutures = [],
        public readonly CombinatorType $combinatorType = CombinatorType::Unknown,
    ) {
    }

    public static function forCompletion(int $completionId): self
    {
        return new self(waitingCompletions: [$completionId]);
    }

    public static function forSignal(int $signalId): self
    {
        return new self(waitingSignals: [$signalId]);
    }

    public function encode(): string
    {
        $writer = new Writer();

        if ($this->waitingCompletions !== []) {
            $writer->writeMessage(1, self::packVarints($this->waitingCompletions));
        }
        if ($this->waitingSignals !== []) {
            $writer->writeMessage(2, self::packVarints($this->waitingSignals));
        }
        foreach ($this->waitingNamedSignals as $name) {
            $writer->writeStringPresent(3, $name);
        }
        foreach ($this->nestedFutures as $nested) {
            $writer->writeMessage(4, $nested->encode());
        }
        $writer->writeUint32(5, $this->combinatorType->value);

        return $writer->toString();
    }

    /**
     * Packed repeated varint encoding (proto3 default for repeated scalar fields).
     *
     * @param list<int> $values
     */
    private static function packVarints(array $values): string
    {
        $packed = '';
        foreach ($values as $value) {
            $packed .= Writer::varint($value);
        }

        return $packed;
    }
}
