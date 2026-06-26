<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Fiber;
use Qcodr\Restate\Sdk\Protocol\Message\Future;

/**
 * The streaming {@see Suspender}: suspends the running fiber, handing the await
 * tree to the driver instead of writing a `SuspensionMessage`. The driver keeps
 * the response open, waits for the runtime to deliver one of the awaited results,
 * feeds it into the state machine, and resumes the fiber — at which point
 * {@see park} returns and the parking await re-checks its readiness.
 *
 * The handler runs inside a {@see \Fiber} the driver started; calling this outside
 * a fiber raises a {@see \FiberError}, which is the correct signal that streaming
 * was wired up without a driver.
 */
final class FiberSuspender implements Suspender
{
    public function park(StateMachine $vm, Future $awaitTree): void
    {
        Fiber::suspend($awaitTree);
    }
}
