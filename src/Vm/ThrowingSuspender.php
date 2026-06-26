<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Closure;
use Qcodr\Restate\Sdk\Protocol\Message\Future;

/**
 * The default, request/response {@see Suspender}: writes the `SuspensionMessage`
 * for the await point and throws {@see SuspendException} to unwind the handler. The
 * endpoint flushes the buffered output and closes the response; the runtime
 * re-invokes the SDK on the next slice.
 *
 * This reproduces exactly the behavior the state machine had inline before the
 * suspender abstraction, so the bytes emitted on the request/response path are
 * unchanged.
 */
final class ThrowingSuspender implements Suspender
{
    public function park(StateMachine $vm, Future $awaitTree, Closure $isResolved): void
    {
        // The readiness predicate is irrelevant here: request/response always unwinds.
        $vm->writeSuspension($awaitTree);

        throw new SuspendException();
    }
}
