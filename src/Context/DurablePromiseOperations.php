<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

/**
 * Durable promise operations shared by workflow contexts.
 *
 * A durable promise is a named, write-once rendezvous scoped to a workflow key: the
 * `run` handler awaits it while interaction (shared) handlers resolve or reject it,
 * enabling human-in-the-loop steps, webhooks, and signals.
 */
interface DurablePromiseOperations
{
    /** Awaits the named promise, blocking (suspending) until it is completed. */
    public function promise(string $name): mixed;

    /** Reads the named promise without blocking: returns its value, or null if not yet completed. */
    public function peekPromise(string $name): mixed;

    public function resolvePromise(string $name, mixed $value = null): void;

    public function rejectPromise(string $name, string $reason): void;
}
