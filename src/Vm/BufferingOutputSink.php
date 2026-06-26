<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

/**
 * The default {@see OutputSink}: accumulates frames in memory so the endpoint can
 * drain them in one shot ({@see take}) after the slice finishes. This is the exact
 * behavior the request/response transport relied on before the sink abstraction
 * existed, so the emitted bytes are unchanged.
 */
final class BufferingOutputSink implements OutputSink
{
    private string $buffer = '';

    public function write(string $frame): void
    {
        $this->buffer .= $frame;
    }

    /** Returns the buffered frames and clears the buffer. */
    public function take(): string
    {
        $buffer = $this->buffer;
        $this->buffer = '';

        return $buffer;
    }
}
