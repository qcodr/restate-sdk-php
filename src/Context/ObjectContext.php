<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

/**
 * The context for exclusive (single-writer) Virtual Object handlers. Adds state
 * writes on top of the shared read access.
 */
interface ObjectContext extends SharedObjectContext
{
    public function set(string $key, mixed $value): void;

    public function clear(string $key): void;

    public function clearAll(): void;
}
