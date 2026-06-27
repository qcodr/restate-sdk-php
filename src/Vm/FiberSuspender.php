<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Closure;
use Fiber;
use Qcodr\Restate\Sdk\Protocol\Message\Future;

/**
 * The streaming {@see Suspender}: suspends the running fiber, yielding a
 * {@see ParkSignal} (the await tree plus its readiness predicate) to the driver
 * instead of writing a `SuspensionMessage`. The driver keeps the response open,
 * feeds late results as the runtime delivers them, and resumes the fiber only once
 * the predicate holds — at which point {@see park} returns and the parking await
 * runs on with its result guaranteed present (no re-check loop).
 *
 * The handler runs inside a {@see \Fiber} the driver started; calling this outside
 * a fiber raises a {@see \FiberError}, which is the correct signal that streaming
 * was wired up without a driver.
 *
 * Before parking it announces the await tree to the runtime with an
 * {@see \Qcodr\Restate\Sdk\Protocol\Message\AwaitingOnMessage} (V7): on an open bidi
 * stream the runtime only pushes a completion/signal once it knows the invocation is
 * waiting on it, so without this a parked invocation never receives an external CANCEL
 * signal or an awakeable resolved by another invocation.
 */
final class FiberSuspender implements Suspender
{
    public function park(StateMachine $vm, Future $awaitTree, Closure $isResolved): void
    {
        $vm->writeAwaitingOn($awaitTree);
        Fiber::suspend(new ParkSignal($awaitTree, $isResolved));
    }
}
