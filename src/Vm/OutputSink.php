<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

/**
 * Where the state machine writes its encoded outgoing frames.
 *
 * The request/response transport buffers frames and flushes them once the slice
 * ends ({@see BufferingOutputSink}); a streaming transport can instead push each
 * frame onto the open response body the moment it is produced.
 */
interface OutputSink
{
    /** Accepts one fully encoded outgoing frame (header + payload). */
    public function write(string $frame): void;
}
