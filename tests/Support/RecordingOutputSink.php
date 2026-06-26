<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support;

use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Vm\OutputSink;

/**
 * A non-buffering {@see OutputSink} for streaming tests: it records every frame the
 * state machine pushes (instead of holding them for {@see \Qcodr\Restate\Sdk\Vm\StateMachine::takeOutput()}),
 * so a test can assert exactly which frames were emitted while the handler was parked.
 *
 * @internal test support
 */
final class RecordingOutputSink implements OutputSink
{
    /** @var list<string> the raw encoded frames, in write order */
    private array $frames = [];

    public function write(string $frame): void
    {
        $this->frames[] = $frame;
    }

    /** @return list<string> */
    public function frames(): array
    {
        return $this->frames;
    }

    /**
     * The message type of every recorded frame, decoded in write order.
     *
     * @return list<MessageType|null>
     */
    public function frameTypes(): array
    {
        return \array_map(
            static fn (string $frame): ?MessageType => MessageCodec::decodeAll($frame)[0]->type(),
            $this->frames,
        );
    }
}
