<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Closure;
use Qcodr\Restate\Sdk\Protocol\Message\Future;

/**
 * The value a parking fiber yields to its streaming driver.
 *
 * It carries both the cancel-guarded await tree — so the driver can write a
 * `SuspensionMessage` if the runtime hangs up while the fiber is parked — and the
 * readiness predicate the driver evaluates after feeding each frame. The fiber is
 * resumed only once {@see $isResolved} returns true, so the parking await is
 * guaranteed its own result (or a cancel) is present when it runs on: each await is
 * straight-line, with no busy re-check loop.
 */
final class ParkSignal
{
    /**
     * @param Closure(): bool $isResolved true once the awaited result (or a cancel) is
     *                                    present, so the driver may resume the fiber
     */
    public function __construct(
        public readonly Future $awaitTree,
        public readonly Closure $isResolved,
    ) {
    }
}
