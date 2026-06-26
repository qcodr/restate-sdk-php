<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * An awakeable: a durable callback handle. The {@see id} is handed to an external
 * system (or another invocation); when that system resolves or rejects the
 * awakeable, {@see await} returns the value (or raises a terminal failure).
 */
final class Awakeable
{
    public function __construct(
        private readonly string $id,
        private readonly DurableFuture $future,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function await(): mixed
    {
        return $this->future->await();
    }
}
