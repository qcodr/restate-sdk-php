<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Fiber;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SwitchableOutputSink;

/**
 * Outcome of {@see RequestProcessor::tryDriveStreamingInline}: carries the result of
 * running the journal replay and the handler's first execution slice in the calling
 * fiber rather than a separate `async()` task.
 *
 * Two exclusive states:
 *
 *  - **Completed** (`$completed === true`): the handler ran to completion without any
 *    unresolved park.  `$output` holds the full encoded response body; the remaining
 *    fields are null.  The caller can return a {@see \Amp\ByteStream\ReadableBuffer} and
 *    avoid both the outbound {@see \Amp\Pipeline\Queue} and the `async()` continuation
 *    entirely.
 *
 *  - **Parked** (`$completed === false`): the handler is suspended on an unresolved
 *    await.  `$output` holds the pre-park preamble (AwaitingOn and any commands written
 *    before the first park); `$vm`, `$handlerFiber`, `$park` and `$switchSink` are all
 *    non-null.  The caller must:
 *    1. Call {@see SwitchableOutputSink::switchToDownstream} so future writes route
 *       through the streaming transport.
 *    2. Push `$output` (if non-empty) onto the outbound queue so the runtime receives
 *       the preamble.
 *    3. Start an `async()` continuation that calls
 *       {@see RequestProcessor::continueStreamingFromPark}.
 *
 * Both states are immutable once constructed; the only intentional mutation is the call
 * to `switchToDownstream` on the parked `$switchSink`.
 */
final class StreamingInlineResult
{
    /**
     * @param Fiber<mixed, mixed, mixed, mixed>|null $handlerFiber null when completed
     * @param mixed $park the ParkSignal the handler last yielded, or null when completed
     */
    public function __construct(
        /** True when the handler ran to completion without an unresolved park. */
        public readonly bool $completed,
        /**
         * Completed: full encoded response body.
         * Parked: pre-park preamble to flush before the continuation starts.
         */
        public readonly string $output,
        /** null when completed; the live VM to route completions through. */
        public readonly ?StateMachine $vm,
        /** null when completed; the parked handler fiber to resume. */
        public readonly ?Fiber $handlerFiber,
        /** null when completed; the {@see \Qcodr\Restate\Sdk\Vm\ParkSignal} yielded last. */
        public readonly mixed $park,
        /**
         * null when completed; the two-phase sink wired into the VM.
         * Switch it to the streaming downstream before starting the async continuation.
         */
        public readonly ?SwitchableOutputSink $switchSink,
    ) {
    }
}
