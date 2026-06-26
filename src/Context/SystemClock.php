<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * Default {@see Clock} backed by the system wall clock.
 */
final class SystemClock implements Clock
{
    public function nowMillis(): int
    {
        return (int) (\microtime(true) * 1000);
    }
}
