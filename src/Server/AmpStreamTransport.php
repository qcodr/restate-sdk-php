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
 *  - {@see write} enqueues an outbound frame onto the response {@see Queue}. It uses
 *    `pushAsync()`, which buffers and returns immediately rather than awaiting consumer
 *    backpressure, so the handler fiber the driver controls is never parked by amphp —
 *    it parks only on its own await points (via the {@see \Qcodr\Restate\Sdk\Vm\FiberSuspender});
 *  - {@see close} completes the queue exactly once, ending the streamed response body.
 *
 * Live-socket transport glue, like {@see SwooleServer}: it requires amphp/http-server
 * and a real HTTP/2 connection, so it is exercised by the cross-SDK conformance suite
 * rather than unit tests.
 */
final class AmpStreamTransport implements StreamTransport
{
    private bool $closed = false;

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
        return $this->inbound->read();
    }

    public function write(string $bytes): void
    {
        // pushAsync buffers and returns immediately (no consumer-backpressure await), so
        // the handler fiber is never suspended by amphp here. ignore() silences the
        // returned future so a client disconnect — which disposes the queue — does not
        // surface as an unhandled future error.
        $this->outbound->pushAsync($bytes)->ignore();
    }

    public function close(): void
    {
        // Idempotent: the driver closes on its terminal path and the server closes again
        // in a finally guard; completing an already-complete queue would throw.
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->outbound->complete();
    }
}
