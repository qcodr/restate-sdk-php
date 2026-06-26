<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Closure;
use Qcodr\Restate\Sdk\Protocol\Message\Future;

/**
 * The strategy the state machine uses to park an invocation at an await point.
 *
 * Request/response transport unwinds the handler with a {@see SuspendException}
 * after writing a `SuspensionMessage` ({@see ThrowingSuspender}); bidirectional
 * streaming instead yields the running fiber back to its driver, which resumes it
 * once the awaited result arrives ({@see FiberSuspender}).
 */
interface Suspender
{
    /**
     * Parks the invocation on the given (already cancel-guarded) await tree. It either
     * does not return (it threw to unwind the handler) or returns once the driver has
     * fed the awaited result and resumed execution.
     *
     * @param Closure(): bool $isResolved the await's own readiness predicate; the
     *                                    streaming driver resumes the fiber only once it
     *                                    holds, so the await runs on with its result
     *                                    guaranteed present. The request/response
     *                                    suspender ignores it (it unwinds unconditionally).
     */
    public function park(StateMachine $vm, Future $awaitTree, Closure $isResolved): void;
}
