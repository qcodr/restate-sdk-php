<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

/**
 * A two-phase {@see OutputSink} that first buffers frames to an internal string and
 * then, once {@see switchToDownstream} is called, forwards all subsequent frames to
 * another sink.
 *
 * This is used by the bidi streaming fast-path: the handler's first execution slice
 * (journal replay + first `async()` dispatch) runs in the calling fiber with output
 * buffered here.  If the handler completes without parking the buffer is returned as
 * a single {@see \Amp\ByteStream\ReadableBuffer}, eliminating the outbound
 * {@see \Amp\Pipeline\Queue} and the `async()` fiber entirely.  If the handler parks,
 * the buffer is flushed as the response-body preamble and this sink is forwarded to a
 * {@see \Qcodr\Restate\Sdk\Endpoint\StreamingOutputSink} backed by an
 * {@see \Qcodr\Restate\Sdk\Server\AmpStreamTransport}, so subsequent slices stream
 * through the normal Queue path with no protocol change.
 *
 * The mutation ({@see switchToDownstream}) is intentional and bounded: each instance
 * is created per-invocation, used only by the driving loop in the same fiber, and
 * discarded when the invocation ends.  No sharing, no aliasing across invocations.
 */
final class SwitchableOutputSink implements OutputSink
{
    private string $buffer = '';

    private ?OutputSink $downstream = null;

    public function write(string $frame): void
    {
        if ($this->downstream !== null) {
            $this->downstream->write($frame);
        } else {
            $this->buffer .= $frame;
        }
    }

    /**
     * Returns and clears the buffered pre-park frames. Must be called while the handler
     * fiber is not running (i.e. after {@see \Fiber::start()} or {@see \Fiber::resume()}
     * has returned) to avoid a data race.
     */
    public function takeBuffer(): string
    {
        $buf = $this->buffer;
        $this->buffer = '';

        return $buf;
    }

    /**
     * Routes all future {@see write} calls to $sink.  Any frames already buffered are
     * NOT forwarded — the caller is responsible for pushing {@see takeBuffer()} to the
     * consumer before activating the downstream.
     */
    public function switchToDownstream(OutputSink $sink): void
    {
        $this->downstream = $sink;
    }
}
