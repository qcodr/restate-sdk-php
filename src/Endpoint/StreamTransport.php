<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

/**
 * The bidirectional byte pipe an {@see InvocationDriver} uses to stream one
 * invocation: it reads inbound runtime frames (StartMessage + journal, then late
 * completions/signals) and writes outbound command/terminal frames the moment the
 * handler produces them.
 *
 * It is the streaming counterpart of the request/response {@see HttpRequest} /
 * {@see HttpResponse} pair: where r/r hands the driver the whole body at once and
 * collects the whole response, a streaming transport keeps the channel open so
 * frames flow in both directions for the lifetime of the invocation.
 */
interface StreamTransport
{
    /**
     * Returns the next inbound bytes, or null once the runtime has closed its half of
     * the channel (EOF). A chunk may carry part of a frame, a whole frame, or several;
     * the state machine reassembles them.
     */
    public function read(): ?string;

    /** Writes one or more encoded outbound frames to the open response. */
    public function write(string $bytes): void;

    /** Closes the channel once the invocation has produced its terminal frame. */
    public function close(): void;
}
