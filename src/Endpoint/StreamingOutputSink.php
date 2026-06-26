<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Qcodr\Restate\Sdk\Vm\OutputSink;

/**
 * The streaming {@see OutputSink}: pushes each encoded frame straight onto an open
 * {@see StreamTransport} as the handler produces it, instead of buffering for a
 * one-shot drain like {@see \Qcodr\Restate\Sdk\Vm\BufferingOutputSink}.
 *
 * Commands therefore reach the runtime while the invocation is still parked, and the
 * terminal frame (Output/End/Error) is on the wire before the driver closes the
 * channel — so {@see \Qcodr\Restate\Sdk\Vm\StateMachine::takeOutput()} has nothing
 * left to return.
 */
final class StreamingOutputSink implements OutputSink
{
    public function __construct(private readonly StreamTransport $io)
    {
    }

    public function write(string $frame): void
    {
        $this->io->write($frame);
    }
}
