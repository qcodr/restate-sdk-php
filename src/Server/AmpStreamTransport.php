<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Server;

use Amp\Http\Server\RequestBody;
use Amp\Pipeline\Queue;
use Qcodr\Restate\Sdk\Endpoint\StreamTransport;

/**
 * Adapts amphp's two HTTP/2 half-channels into the bidirectional {@see StreamTransport}
 * the streaming {@see \Qcodr\Restate\Sdk\Endpoint\InvocationDriver} drives:
 *
 *  - {@see read} pulls the next inbound chunk from the request body, returning null at
 *    EOF (the runtime closed its half of the stream);
 *  - {@see write} appends an outbound frame to a slice buffer (it does not hit the socket
 *    yet); the buffer is flushed as a single {@see Queue} push when the driver next blocks
 *    on {@see read} or finishes via {@see close}. Coalescing every frame a slice produces
 *    (e.g. Output then End, or several commands before one park) into one write halves the
 *    socket writes and avoids an inter-frame delayed-ACK stall, while preserving the
 *    invariant that all pending frames reach the runtime before the driver waits for input.
 *    The flush uses `pushAsync()`, which buffers and returns immediately rather than awaiting
 *    consumer backpressure, so the handler fiber the driver controls is never parked by amphp
 *    — it parks only on its own await points (via the {@see \Qcodr\Restate\Sdk\Vm\FiberSuspender});
 *  - {@see close} flushes any buffered frames and completes the queue exactly once, ending
 *    the streamed response body.
 *
 * Live-socket transport glue, like {@see SwooleServer}: it requires amphp/http-server
 * and a real HTTP/2 connection, so it is exercised by the cross-SDK conformance suite
 * rather than unit tests.
 */
final class AmpStreamTransport implements StreamTransport
{
    private bool $closed = false;

    /** Frames written during the current slice, flushed as one push on read/close. */
    private string $buffer = '';

    /**
     * @param Queue<string> $outbound the response body queue frames are pushed onto
     */
    public function __construct(
        private readonly RequestBody $inbound,
        private readonly Queue $outbound,
    ) {
    }

    public function read(): ?string
    {
        // Flush before blocking: the driver only reads when it needs the next inbound
        // frame, so every command/terminal frame the just-run slice produced must be on
        // the wire first (otherwise a parked await would deadlock waiting for a completion
        // to a command the runtime never received).
        $this->flush();

        return $this->inbound->read();
    }

    public function write(string $bytes): void
    {
        // Accumulate; the actual push happens in flush() so a whole slice's frames go out
        // as one write (see the class docblock).
        $this->buffer .= $bytes;
    }

    public function close(): void
    {
        // Idempotent: the driver closes on its terminal path and the server closes again
        // in a finally guard; completing an already-complete queue would throw.
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->flush();
        $this->outbound->complete();
    }

    /**
     * Pushes the buffered frames as a single queue item, then clears the buffer. pushAsync
     * buffers and returns immediately (no consumer-backpressure await), so the handler fiber
     * is never suspended here; ignore() silences the returned future so a client disconnect —
     * which disposes the queue — does not surface as an unhandled future error.
     */
    private function flush(): void
    {
        if ($this->buffer === '') {
            return;
        }
        $this->outbound->pushAsync($this->buffer)->ignore();
        $this->buffer = '';
    }
}
