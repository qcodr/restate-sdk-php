<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

/**
 * Wall-clock source for computing absolute timer deadlines (sleep wake-up times,
 * delayed sends). Abstracted so tests can supply a deterministic clock.
 *
 * Determinism note: deadlines are journaled the first time they are issued and not
 * recomputed on replay, so reading the real clock here is safe.
 */
interface Clock
{
    public function nowMillis(): int;
}
